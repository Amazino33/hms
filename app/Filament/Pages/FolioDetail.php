<?php

namespace App\Filament\Pages;

use App\Models\Booking;
use App\Models\IncidentalPriceListItem;
use App\Services\BookingService;
use App\Services\FolioService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Http\Request;

class FolioDetail extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.folio-detail';

    protected static ?string $slug = 'folio';

    public static function canAccess(): bool
    {
        return \App\Services\PermissionService::canAccessPage(self::class);
    }

    public ?Booking $booking = null;

    public ?int $selectedPriceListItemId = null;

    public string $incidentalDescription = '';

    public ?float $incidentalAmount = null;

    public ?float $paymentAmount = null;

    public string $paymentMethod = 'cash';

    public string $paymentReference = '';

    public function mount(Request $request)
    {
        $bookingId = $request->query('booking');

        if (! $bookingId) {
            return redirect('/admin/reservations-timeline');
        }

        $this->loadBooking($bookingId);

        if (! $this->booking) {
            return redirect('/admin/reservations-timeline');
        }
    }

    protected function loadBooking($bookingId): void
    {
        $this->booking = Booking::with(['guest', 'room', 'folio.lines.createdBy', 'folio.lines.verifiedBy'])->find($bookingId);
    }

    public function priceListItems()
    {
        return IncidentalPriceListItem::active()->orderBy('name')->get();
    }

    public function applyPriceListItem(int $itemId): void
    {
        $item = IncidentalPriceListItem::find($itemId);

        if (! $item) {
            return;
        }

        $this->selectedPriceListItemId = $itemId;
        $this->incidentalDescription = $item->name;
        $this->incidentalAmount = (float) $item->price;
    }

    public function addIncidental(): void
    {
        try {
            $folio = $this->booking->folio ?? $this->booking->folio()->create();

            (new FolioService())->postIncidental(
                $folio,
                $this->incidentalDescription,
                (float) $this->incidentalAmount,
                auth()->id(),
            );

            $this->incidentalDescription = '';
            $this->incidentalAmount = null;
            $this->selectedPriceListItemId = null;
            $this->loadBooking($this->booking->id);

            Notification::make()->title('Charge added')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not add charge')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    public function recordPayment(): void
    {
        try {
            $folio = $this->booking->folio ?? $this->booking->folio()->create();

            (new FolioService())->recordPayment(
                $folio,
                (float) $this->paymentAmount,
                $this->paymentMethod,
                $this->paymentReference ?: null,
                auth()->id(),
            );

            $this->paymentAmount = null;
            $this->paymentReference = '';
            $this->loadBooking($this->booking->id);

            Notification::make()->title('Payment recorded')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not record payment')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    public function checkIn(): void
    {
        try {
            (new BookingService())->checkIn($this->booking, auth()->id());

            $this->loadBooking($this->booking->id);

            Notification::make()->title('Guest checked in')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not check in')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    public function checkOut(): void
    {
        try {
            (new BookingService())->checkOut($this->booking, auth()->id());

            $this->loadBooking($this->booking->id);

            Notification::make()->title('Guest checked out')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not check out')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    public function getTitle(): string
    {
        return $this->booking ? "Folio — Room {$this->booking->room->number}" : 'Folio';
    }
}
