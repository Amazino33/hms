<?php

namespace App\Filament\Pages;

use App\Models\OwnerTakeNote;
use App\Services\PermissionService;
use App\Services\ReceptionistShiftService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use UnitEnum;

/**
 * Standalone, deliberately not the shared topbar ShiftManager widget — that
 * widget's start/end flow is order-based (waiter) and PIN-sealed-handover-
 * based (bartender/chef), neither of which fits a receptionist's till-float
 * + folio-payment accountability. Keeping this separate means the existing
 * widget for every other role is never touched.
 */
class ReceptionistShift extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static string|UnitEnum|null $navigationGroup = 'Hotel';

    protected static ?string $navigationLabel = 'Receptionist Shift';

    protected static ?string $title = 'Receptionist Shift';

    protected string $view = 'filament.pages.receptionist-shift';

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    public ?float $startingFloat = null;

    public ?float $declaredCash = null;

    public ?float $declaredPos = null;

    public ?float $ownerTakeAmount = null;

    public string $ownerTakeDescription = '';

    /**
     * Not User::currentShift() (whereNull('ended_at')) — once declareEnd()
     * stamps ended_at, that would stop finding the shift at all, and the
     * page needs to keep showing it while it's awaiting_cashier.
     */
    public function currentShift()
    {
        return auth()->user()?->shifts()
            ->whereIn('status', ['active', 'awaiting_cashier'])
            ->latest('started_at')
            ->first();
    }

    public function expectedCashSoFar(): float
    {
        $shift = $this->currentShift();

        return $shift ? (new ReceptionistShiftService())->expectedCashRemittance($shift) : 0.0;
    }

    public function expectedPosSoFar(): float
    {
        $shift = $this->currentShift();

        return $shift ? (new ReceptionistShiftService())->expectedPosTotal($shift) : 0.0;
    }

    public function startShift(): void
    {
        try {
            (new ReceptionistShiftService())->startShift(auth()->user(), (float) ($this->startingFloat ?? 0));

            $this->startingFloat = null;

            Notification::make()->title('Shift started')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not start shift')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    /**
     * Same as ShiftManager::recordOwnerTake() for waiter/cashier — just a
     * note against the shift, no status/approve-reject workflow. The
     * cashier sees it during settlement and the CEO gets read-only
     * visibility in /ceo. Never blocks or changes ending the shift.
     */
    public function recordOwnerTake(): void
    {
        $shift = $this->currentShift();

        if (! $shift) {
            Notification::make()->title('No active shift')->warning()->send();
            return;
        }

        if (trim($this->ownerTakeDescription) === '') {
            Notification::make()->title('Add a short note about what was taken')->danger()->persistent()->send();
            return;
        }

        OwnerTakeNote::create([
            'shift_id' => $shift->id,
            'recorded_by' => auth()->id(),
            'amount' => $this->ownerTakeAmount,
            'description' => trim($this->ownerTakeDescription),
        ]);

        $this->ownerTakeAmount = null;
        $this->ownerTakeDescription = '';

        Notification::make()->title('Noted — the cashier will see this when settling your shift')->success()->send();
    }

    public function declareEnd(): void
    {
        $shift = $this->currentShift();

        if (! $shift) {
            Notification::make()->title('No active shift')->warning()->send();
            return;
        }

        try {
            (new ReceptionistShiftService())->declareEnd($shift, (float) ($this->declaredCash ?? 0), (float) ($this->declaredPos ?? 0));

            $this->declaredCash = null;
            $this->declaredPos = null;

            Notification::make()->title('Shift end declared — awaiting supervisor confirmation')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not end shift')->body($e->getMessage())->danger()->persistent()->send();
        }
    }
}
