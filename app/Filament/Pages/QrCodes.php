<?php

namespace App\Filament\Pages;

use App\Models\Room;
use App\Models\Table;
use App\Services\Guest\QrCodeRenderer;
use App\Services\Guest\QrPrintSheets;
use App\Services\Guest\QrTokens;
use App\Services\PermissionService;
use App\Services\UserFeedback;
use App\Support\GuestUrl;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * "QR Codes" (Phase 1B): every table's and room's guest QR code — preview,
 * PNG download, print sheets, and Regenerate (old code dies at once,
 * reason logged). Gated by PagePermission; every action re-checks it,
 * since a Livewire action request doesn't re-run the page's mount gate.
 */
class QrCodes extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static string|UnitEnum|null $navigationGroup = 'Guest Ordering';

    protected static ?string $navigationLabel = 'QR Codes';

    protected static ?string $title = 'QR Codes';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.qr-codes';

    public string $tab = 'tables';

    public ?string $regeneratingType = null;

    public ?int $regeneratingId = null;

    public string $regenerateReason = '';

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    public function places()
    {
        return $this->tab === 'rooms'
            ? Room::orderBy('number')->get()->map(fn (Room $r) => ['type' => 'room', 'id' => $r->id, 'label' => 'Room '.$r->number, 'url' => $r->guestUrl()])
            : Table::orderBy('name')->get()->map(fn (Table $t) => ['type' => 'table', 'id' => $t->id, 'label' => $t->name, 'url' => $t->guestUrl()]);
    }

    public function previewSvg(?string $url): string
    {
        return $url ? (new QrCodeRenderer)->svg($url) : '';
    }

    public function menuUrl(): string
    {
        return GuestUrl::menu();
    }

    public function downloadPng(string $type, int $id): ?StreamedResponse
    {
        if (! $this->allowed()) {
            return null;
        }

        $place = $this->find($type, $id);

        if (! $place?->qr_token) {
            UserFeedback::blocked('No QR code', 'That table or room has no QR code yet. Refresh the page.');

            return null;
        }

        $label = $place instanceof Room ? 'room-'.$place->number : $place->name;

        return $this->downloadBytes((new QrCodeRenderer)->png($place->guestUrl(), 20), 'qr-'.Str::slug($label).'.png', 'image/png');
    }

    public function downloadMenuPng(): ?StreamedResponse
    {
        return $this->allowed() ? $this->downloadBytes((new QrCodeRenderer)->png($this->menuUrl(), 20), 'qr-menu.png', 'image/png') : null;
    }

    public function printTables(): ?StreamedResponse
    {
        return $this->allowed() ? $this->downloadBytes((new QrPrintSheets)->tables(), 'qr-table-cards.pdf', 'application/pdf') : null;
    }

    public function printRooms(): ?StreamedResponse
    {
        return $this->allowed() ? $this->downloadBytes((new QrPrintSheets)->rooms(), 'qr-room-stickers.pdf', 'application/pdf') : null;
    }

    public function printMenu(): ?StreamedResponse
    {
        return $this->allowed() ? $this->downloadBytes((new QrPrintSheets)->menu(), 'qr-menu-poster.pdf', 'application/pdf') : null;
    }

    public function openRegenerate(string $type, int $id): void
    {
        $this->regeneratingType = $type;
        $this->regeneratingId = $id;
        $this->regenerateReason = '';
    }

    public function closeRegenerate(): void
    {
        $this->regeneratingType = null;
        $this->regeneratingId = null;
        $this->regenerateReason = '';
    }

    public function regenerate(): void
    {
        if (! $this->allowed()) {
            return;
        }

        $place = $this->regeneratingType ? $this->find($this->regeneratingType, (int) $this->regeneratingId) : null;

        if (! $place) {
            UserFeedback::blocked('Not found', 'That table or room no longer exists. Refresh the page.');

            return;
        }

        try {
            QrTokens::regenerate($place, auth()->user(), $this->regenerateReason);
        } catch (\Exception $e) {
            if (get_class($e) !== \Exception::class) {
                throw $e;
            }

            UserFeedback::blocked('QR code not replaced', $e->getMessage());

            return;
        }

        $label = $place instanceof Room ? 'Room '.$place->number : $place->name;
        $this->closeRegenerate();

        UserFeedback::succeeded("New QR code for {$label}", 'The old one stopped working just now. Print and replace the card or sticker.');
    }

    private function find(string $type, int $id): Table|Room|null
    {
        return match ($type) {
            'table' => Table::find($id),
            'room' => Room::find($id),
            default => null,
        };
    }

    private function allowed(): bool
    {
        if (static::canAccess()) {
            return true;
        }

        UserFeedback::blocked('Not allowed', 'Only a manager can manage QR codes.');

        return false;
    }

    private function downloadBytes(string $bytes, string $filename, string $mime): StreamedResponse
    {
        return response()->streamDownload(function () use ($bytes) {
            echo $bytes;
        }, $filename, ['Content-Type' => $mime]);
    }
}
