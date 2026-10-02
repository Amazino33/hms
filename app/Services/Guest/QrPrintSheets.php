<?php

namespace App\Services\Guest;

use App\Models\Company;
use App\Models\Room;
use App\Models\Table;
use App\Support\GuestUrl;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * The printable QR sheets (Phase 1B), as PDF bytes:
 *   tables()  A6 table cards, 4 per A4 (QR 60 mm)
 *   rooms()   80×80 mm room stickers, 6 per A4 (QR 58 mm)
 *   menu()    A5 "See our menu" poster (QR 90 mm)
 * Every card carries dashed cut guides and a black-on-white code.
 */
class QrPrintSheets
{
    public function __construct(private readonly QrCodeRenderer $qr = new QrCodeRenderer) {}

    public function tables(): string
    {
        $cards = Table::orderBy('name')->get()->filter(fn (Table $t) => filled($t->qr_token))->map(fn (Table $table) => [
            'label' => $table->name,
            'qr' => $this->qr->pngDataUri(GuestUrl::forToken($table->qr_token)),
        ])->values();

        return $this->render('pdf.qr.table-cards', $cards->chunk(4), 'a4');
    }

    public function rooms(): string
    {
        $stickers = Room::orderBy('number')->get()->filter(fn (Room $r) => filled($r->qr_token))->map(fn (Room $room) => [
            'label' => 'Room '.$room->number,
            'qr' => $this->qr->pngDataUri(GuestUrl::forToken($room->qr_token)),
        ])->values();

        return $this->render('pdf.qr.room-stickers', $stickers->chunk(6), 'a4');
    }

    public function menu(): string
    {
        return $this->render('pdf.qr.menu-poster', collect([[['qr' => $this->qr->pngDataUri(GuestUrl::menu(), 16)]]]), 'a5');
    }

    private function render(string $view, $pages, string $paper): string
    {
        $company = Company::first();

        return Pdf::loadView($view, [
            'pages' => $pages,
            'venue' => Company::displayName(),
            'logo' => $this->logoDataUri($company),
        ])->setPaper($paper, 'portrait')->output();
    }

    /**
     * Embedded rather than linked: dompdf can't reach the site over HTTP
     * from inside the server reliably, and a broken logo must not break
     * the sheet.
     */
    private function logoDataUri(?Company $company): ?string
    {
        $path = $company?->logo_path;

        if (! $path) {
            return null;
        }

        // The Company Settings upload uses Filament's default disk (which
        // follows FILESYSTEM_DISK — 'local' here); 'public' is checked too.
        foreach (array_unique([config('filament.default_filesystem_disk'), 'public']) as $diskName) {
            $disk = Storage::disk($diskName);

            if ($disk->exists($path)) {
                return 'data:'.($disk->mimeType($path) ?: 'image/png').';base64,'.base64_encode($disk->get($path));
            }
        }

        return null;
    }
}
