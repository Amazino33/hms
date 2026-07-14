<?php

namespace App\Filament\Pages;

use App\Models\FolioLine;
use App\Services\FolioService;
use App\Services\PermissionService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use UnitEnum;

/**
 * A manager's queue of transfer payments awaiting confirmation against
 * the actual bank alert — cash and POS-terminal payments never appear
 * here since they're self-evident at the point of collection.
 */
class TransferVerification extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|UnitEnum|null $navigationGroup = 'Hotel';

    protected static ?string $navigationLabel = 'Transfer Verification';

    protected static ?string $title = 'Transfer Verification';

    protected string $view = 'filament.pages.transfer-verification';

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    public ?int $rejectingLineId = null;

    public string $rejectReason = '';

    public function getViewData(): array
    {
        return [
            'lines' => FolioLine::where('type', 'payment')
                ->where('payment_method', 'transfer')
                ->where('verified', false)
                ->with(['folio.booking.room', 'folio.booking.guest', 'createdBy'])
                ->oldest()
                ->get(),
        ];
    }

    public function verify(int $lineId): void
    {
        try {
            (new FolioService())->verifyTransfer(FolioLine::findOrFail($lineId), auth()->id());

            Notification::make()->title('Transfer verified')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not verify')->body($e->getMessage())->danger()->send();
        }
    }

    public function openReject(int $lineId): void
    {
        $this->rejectingLineId = $lineId;
        $this->rejectReason = '';
    }

    public function closeReject(): void
    {
        $this->rejectingLineId = null;
    }

    public function reject(): void
    {
        if (! $this->rejectingLineId || trim($this->rejectReason) === '') {
            Notification::make()->title('A reason is required to reject a transfer')->warning()->send();
            return;
        }

        try {
            (new FolioService())->rejectTransfer(FolioLine::findOrFail($this->rejectingLineId), $this->rejectReason, auth()->id());

            $this->closeReject();

            Notification::make()->title('Transfer rejected')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not reject')->body($e->getMessage())->danger()->send();
        }
    }
}
