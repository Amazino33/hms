<?php

namespace App\Filament\Pages;

use App\Models\Shift;
use App\Services\CashierSettlementService;
use App\Services\PermissionService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Http\Request;
use UnitEnum;

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
        return $this->shiftId ? Shift::with(['user', 'cashConfirmedBy', 'posConfirmedBy'])->find($this->shiftId) : null;
    }

    public function expectedCash(): float
    {
        return (new CashierSettlementService())->expectedCash($this->shift());
    }

    public function expectedPosMachine(): float
    {
        return (new CashierSettlementService())->expectedPosMachine($this->shift());
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

        return ['total' => $total, 'resolved' => $resolved, 'complete' => (new CashierSettlementService())->transferChannelComplete($shift)];
    }

    public function confirmCash(): void
    {
        try {
            (new CashierSettlementService())->confirmCash($this->shift(), (float) $this->cashierCountedCash, auth()->id());

            $this->cashierCountedCash = null;
            Notification::make()->title('Cash confirmed')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not confirm cash')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    public function confirmPos(): void
    {
        try {
            (new CashierSettlementService())->confirmPos($this->shift(), (float) $this->posMachineAmount, auth()->id());

            $this->posMachineAmount = null;
            Notification::make()->title('POS total confirmed')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not confirm POS')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    public function getTitle(): string
    {
        $shift = $this->shift();

        return $shift ? "Settlement — {$shift->user?->name}" : 'Settlement';
    }
}
