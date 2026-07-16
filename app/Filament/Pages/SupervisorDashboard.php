<?php

namespace App\Filament\Pages;

use App\Models\CashDrop;
use App\Models\CashierSession;
use App\Models\OrderPayment;
use App\Models\Shift;
use App\Services\CashierSessionService;
use App\Services\PermissionService;
use App\Services\SettlementFlagRulingService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use UnitEnum;

/**
 * The only piece of "the broader supervisor module" this build touches —
 * deliberately just the three things named in the spec: open flags,
 * unresolved cashier-session gaps, and pending fallback duties (surfaced
 * for visibility; the fallback actions themselves live on the same
 * screens the cashier uses — TransferQueue, PendingCashDrops,
 * SettlementDetail — which supervisors are also granted access to).
 */
class SupervisorDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static string|UnitEnum|null $navigationGroup = 'Cashier';

    protected static ?string $navigationLabel = 'Supervisor Dashboard';

    protected static ?string $title = 'Supervisor Dashboard';

    protected string $view = 'filament.pages.supervisor-dashboard';

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    public ?int $rulingTransferId = null;

    public ?int $rulingShiftId = null;

    public string $rulingNote = '';

    public function getViewData(): array
    {
        return [
            'flaggedTransfers' => OrderPayment::where('flagged', true)->whereNull('ruling')->with(['order', 'user', 'flaggedBy'])->get(),
            'flaggedPos' => Shift::where('pos_flagged', true)->whereNull('pos_ruling')->with('user')->get(),
            'pendingCashierSessions' => CashierSession::where('status', 'pending_supervisor')->with('user')->get(),
            'unverifiedTransferCount' => OrderPayment::where('method', 'transfer')->where('verified', false)->whereNull('ruling')->count(),
            'pendingDropCount' => CashDrop::where('status', 'pending')->count(),
            'awaitingCashierCount' => Shift::where('status', 'awaiting_cashier')->count(),
        ];
    }

    public function openTransferRuling(int $paymentId): void
    {
        $this->rulingTransferId = $paymentId;
        $this->rulingShiftId = null;
        $this->rulingNote = '';
    }

    public function openPosRuling(int $shiftId): void
    {
        $this->rulingShiftId = $shiftId;
        $this->rulingTransferId = null;
        $this->rulingNote = '';
    }

    public function closeRuling(): void
    {
        $this->rulingTransferId = null;
        $this->rulingShiftId = null;
    }

    public function ruleTransfer(string $ruling): void
    {
        if (trim($this->rulingNote) === '') {
            Notification::make()->title('A note is required')->warning()->send();
            return;
        }

        try {
            $payment = OrderPayment::findOrFail($this->rulingTransferId);
            (new SettlementFlagRulingService())->ruleTransfer($payment, $ruling, $this->rulingNote, auth()->id());

            $this->closeRuling();
            Notification::make()->title('Ruling recorded')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not rule')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    public function rulePos(string $ruling): void
    {
        if (trim($this->rulingNote) === '') {
            Notification::make()->title('A note is required')->warning()->send();
            return;
        }

        try {
            $shift = Shift::findOrFail($this->rulingShiftId);
            (new SettlementFlagRulingService())->rulePosMachine($shift, $ruling, $this->rulingNote, auth()->id());

            $this->closeRuling();
            Notification::make()->title('Ruling recorded')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not rule')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    public ?float $supervisorCountedCash = null;

    public ?int $closingSessionId = null;

    public function openSessionClose(int $sessionId): void
    {
        $this->closingSessionId = $sessionId;
        $this->supervisorCountedCash = null;
    }

    public function confirmSessionClose(): void
    {
        try {
            $session = CashierSession::findOrFail($this->closingSessionId);
            (new CashierSessionService())->confirmCloseBySupervisor($session, (float) $this->supervisorCountedCash, auth()->id());

            $this->closingSessionId = null;
            $this->supervisorCountedCash = null;
            Notification::make()->title('Cashier session closed')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not close session')->body($e->getMessage())->danger()->persistent()->send();
        }
    }
}
