<?php

namespace App\Filament\Pages;

use App\Models\Booking;
use App\Models\IncidentalPriceListItem;
use App\Models\Room;
use App\Models\RoomSupply;
use App\Models\WareHouse;
use App\Services\BookingService;
use App\Services\Ceo\RoomProfitService;
use App\Services\FolioService;
use App\Services\RoomSupplyService;
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

    public ?float $discountAmount = null;

    public string $discountReason = '';

    public ?float $paymentAmount = null;

    // Most room-charge payments come through POS terminal, not cash — this
    // default (and the chip-select's left-to-right order in the blade view)
    // avoids receptionists leaving the pre-selected chip on the wrong method.
    public string $paymentMethod = 'pos_terminal';

    public string $paymentReference = '';

    public ?int $selectedRoomSupplyId = null;

    public ?float $roomSupplyQuantity = null;

    public ?int $extendNights = null;

    public ?int $newRoomId = null;

    public ?string $roomChangeReason = null;

    public string $roomChangeNote = '';

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

            (new FolioService)->postIncidental(
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

    public function applyDiscount(): void
    {
        try {
            $folio = $this->booking->folio ?? $this->booking->folio()->create();

            (new FolioService)->applyDiscount(
                $folio,
                (float) $this->discountAmount,
                $this->discountReason,
                auth()->id(),
            );

            $this->discountAmount = null;
            $this->discountReason = '';
            $this->loadBooking($this->booking->id);

            Notification::make()->title('Discount applied')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not apply discount')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    public function recordPayment(): void
    {
        try {
            $folio = $this->booking->folio ?? $this->booking->folio()->create();

            (new FolioService)->recordPayment(
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

    public function roomSupplies()
    {
        return RoomSupply::where('is_active', true)->orderBy('name')->get();
    }

    public function roomSupplyUsages()
    {
        return $this->booking->roomSupplyUsages()->with('roomSupply')->latest()->get();
    }

    public function roomProfit(): array
    {
        return (new RoomProfitService())->forBooking($this->booking);
    }

    public function recordRoomSupplyUsage(): void
    {
        $roomSupply = RoomSupply::find($this->selectedRoomSupplyId);
        $warehouse = WareHouse::where('type', 'storage')->first();

        if (! $roomSupply || ! $warehouse) {
            Notification::make()->title('Pick a room supply first')->warning()->send();

            return;
        }

        try {
            app(RoomSupplyService::class)->recordUsage(
                $this->booking,
                $roomSupply,
                $warehouse,
                (float) $this->roomSupplyQuantity,
                auth()->id(),
            );

            $this->selectedRoomSupplyId = null;
            $this->roomSupplyQuantity = null;

            Notification::make()->title('Usage recorded')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not record usage')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    public function checkIn(): void
    {
        try {
            (new BookingService)->checkIn($this->booking, auth()->id());

            $this->loadBooking($this->booking->id);

            Notification::make()->title('Guest checked in')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not check in')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    public function extendStay(): void
    {
        try {
            (new BookingService)->extendStay($this->booking, (int) $this->extendNights, auth()->id());

            $this->extendNights = null;
            $this->loadBooking($this->booking->id);

            Notification::make()->title('Stay renewed')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not renew stay')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    public function candidateRoomsForChange()
    {
        return Room::where('status', '!=', 'maintenance')
            ->where('id', '!=', $this->booking->room_id)
            ->orderBy('number')
            ->get()
            ->reject(fn (Room $room) => $room->isOccupiedToday());
    }

    public function roomChangeReasons(): array
    {
        return BookingService::ROOM_CHANGE_REASONS;
    }

    public function changeRoom(): void
    {
        if (! $this->newRoomId || ! $this->roomChangeReason) {
            Notification::make()->title('Pick a room and a reason first')->warning()->send();

            return;
        }

        try {
            (new BookingService)->changeRoom(
                $this->booking,
                (int) $this->newRoomId,
                (string) $this->roomChangeReason,
                $this->roomChangeNote ?: null,
                auth()->id(),
            );

            $this->newRoomId = null;
            $this->roomChangeReason = null;
            $this->roomChangeNote = '';
            $this->loadBooking($this->booking->id);

            Notification::make()->title('Room changed')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not change room')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    public function checkOut(): void
    {
        try {
            (new BookingService)->checkOut($this->booking, auth()->id());

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
