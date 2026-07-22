<?php

namespace App\Filament\Pages;

use App\Models\Shift;
use App\Models\ShiftChannelConfirmation;
use App\Models\StaffDebt;
use App\Services\CashierSettlementService;
use App\Services\PermissionService;
use App\Services\ShiftAccountingService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Http\Request;

/**
 * Shared by the cashier (primary confirmer) and a supervisor (fallback,
 * identical mechanics, distinctly logged) — one screen instead of two,
 * since the confirmation logic is exactly the same either way.
 *
 * Blind by construction, not by hiding a value the code still has: this
 * page never assigns declared_cash/declared_pos to a public property (and
 * therefore never embeds them in the Livewire wire:snapshot, which is
 * visible in page source regardless of what's visually hidden) until
 * AFTER cash_confirmed_at is already set on the shift. There is no
 * "reveal" step to forget — the data simply isn't loaded before that
 * point.
 *
 * A waiter shift that served both bar and kitchen confirms cash/POS
 * separately per destination (CashierSettlementService::usesChannelSplit())
 * — everyone else (receptionist always, or a waiter who only served one
 * destination) still sees the original single combined Cash/POS panel.
 */
class SettlementDetail extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-check-badge';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.settlement-detail';

    protected static ?string $slug = 'settlement';

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    public ?int $shiftId = null;

    public ?float $cashierCountedCash = null;

    public ?float $posMachineAmount = null;

    public ?float $barCashAmount = null;

    public ?float $barPosAmount = null;

    public ?float $kitchenCashAmount = null;

    public ?float $kitchenPosAmount = null;

    public bool $showDebtForm = false;

    public ?float $debtAmount = null;

    public string $debtNotes = '';

    public function mount(Request $request)
    {
        $shiftId = $request->query('shift');

        if (! $shiftId || ! Shift::find($shiftId)) {
            return redirect('/admin/shift-management');
        }

        $this->shiftId = (int) $shiftId;
    }

    public function shift(): ?Shift
    {
        return $this->shiftId ? Shift::with(['user', 'cashConfirmedBy', 'posConfirmedBy', 'channelConfirmations.confirmedBy'])->find($this->shiftId) : null;
    }

    public function usesChannelSplit(): bool
    {
        return (new CashierSettlementService)->usesChannelSplit($this->shift());
    }

    public function activeDestinations(): array
    {
        return (new CashierSettlementService)->activeDestinations($this->shift());
    }

    public function channelConfirmation(string $destination, string $channel): ?ShiftChannelConfirmation
    {
        return $this->shift()->channelConfirmations
            ->first(fn (ShiftChannelConfirmation $c) => $c->destination === $destination && $c->channel === $channel);
    }

    public function expectedForDestination(string $destination, string $channel): float
    {
        $accounting = new ShiftAccountingService;

        return $channel === 'cash'
            ? $accounting->expectedCashForDestination($this->shift(), $destination)
            : $accounting->expectedPosForDestination($this->shift(), $destination);
    }

    /**
     * Just visibility for the cashier while settling — recording it never
     * blocked ending the shift, and confirming here never touches it
     * either. The cashier uses their own judgment (the existing manual
     * Staff Debt form) to decide what, if anything, to record.
     */
    public function ownerTakeNotes()
    {
        return $this->shiftId
            ? \App\Models\OwnerTakeNote::where('shift_id', $this->shiftId)->latest()->get()
            : collect();
    }

    public function staffDebts()
    {
        $shift = $this->shift();

        return $shift ? StaffDebt::where('user_id', $shift->user_id)->where('status', '!=', 'settled')->latest()->get() : collect();
    }

    public function openDebtForm(): void
    {
        $this->showDebtForm = true;
        $this->debtAmount = null;
        $this->debtNotes = '';
    }

    public function cancelDebtForm(): void
    {
        $this->showDebtForm = false;
    }

    public function recordDebt(): void
    {
        $shift = $this->shift();

        if (! $shift) {
            return;
        }

        if (! $this->debtAmount || $this->debtAmount <= 0) {
            Notification::make()->title('Enter an amount greater than zero')->danger()->persistent()->send();

            return;
        }

        StaffDebt::create([
            'user_id' => $shift->user_id,
            'shift_id' => $shift->id,
            'amount' => $this->debtAmount,
            'reason' => 'manual',
            'status' => 'open',
            'created_by' => auth()->id(),
            'notes' => $this->debtNotes ?: null,
        ]);

        $this->showDebtForm = false;
        $this->debtAmount = null;
        $this->debtNotes = '';

        Notification::make()->title('Debt recorded')->success()->send();
    }

    public function expectedCash(): float
    {
        return (new CashierSettlementService)->expectedCash($this->shift());
    }

    public function expectedPosMachine(): float
    {
        return (new CashierSettlementService)->expectedPosMachine($this->shift());
    }

    public function transferSummary(): array
    {
        $shift = $this->shift();

        if ($shift->type === 'receptionist') {
            $query = \App\Models\FolioLine::where('shift_id', $shift->id)->where('type', 'payment')->where('payment_method', 'transfer');
            $total = (clone $query)->count();
            $resolved = (clone $query)->where('verified', true)->count();
        } else {
            $query = \App\Models\OrderPayment::where('shift_id', $shift->id)->where('method', 'transfer');
            $total = (clone $query)->count();
            $resolved = (clone $query)->where(fn ($q) => $q->where('verified', true)->orWhereNotNull('ruling'))->count();
        }

        return ['total' => $total, 'resolved' => $resolved, 'complete' => (new CashierSettlementService)->transferChannelComplete($shift)];
    }

    public function confirmCash(): void
    {
        try {
            (new CashierSettlementService)->confirmCash($this->shift(), (float) $this->cashierCountedCash, auth()->id());

            $this->cashierCountedCash = null;
            Notification::make()->title('Cash confirmed')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not confirm cash')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    public function confirmPos(): void
    {
        try {
            (new CashierSettlementService)->confirmPos($this->shift(), (float) $this->posMachineAmount, auth()->id());

            $this->posMachineAmount = null;
            Notification::make()->title('POS total confirmed')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not confirm POS')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    public function confirmChannel(string $destination, string $channel): void
    {
        $property = $destination.ucfirst($channel).'Amount'; // e.g. barCashAmount

        try {
            (new CashierSettlementService)->confirmChannelForDestination($this->shift(), $destination, $channel, (float) $this->{$property}, auth()->id());

            $this->{$property} = null;
            Notification::make()->title(ucfirst($destination).' '.($channel === 'cash' ? 'cash' : 'POS').' confirmed')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not confirm')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    public function getTitle(): string
    {
        $shift = $this->shift();

        return $shift ? "Settlement — {$shift->user?->name}" : 'Settlement';
    }
}
