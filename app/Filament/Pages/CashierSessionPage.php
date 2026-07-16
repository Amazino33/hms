<?php

namespace App\Filament\Pages;

use App\Models\CashierSession;
use App\Services\CashierSessionService;
use App\Services\PermissionService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use UnitEnum;

/**
 * Her own custody-chain session — visiting this page counts as the
 * "explicit open" the spec allows alongside auto-open-on-first-action.
 * Deliberately never blocks on a previous unclosed/gap-carrying session
 * (CashierSessionService::currentOrOpen() has no such check) — that
 * asymmetry versus the staff shift-start gate is intentional.
 */
class CashierSessionPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-wallet';

    protected static string|UnitEnum|null $navigationGroup = 'Cashier';

    protected static ?string $navigationLabel = 'My Session';

    protected static ?string $title = 'My Cashier Session';

    protected string $view = 'filament.pages.cashier-session-page';

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    public ?float $outflowAmount = null;

    public string $outflowType = 'deposit';

    public string $outflowNote = '';

    public ?float $declaredClosingCash = null;

    public function mount(): void
    {
        (new CashierSessionService())->currentOrOpen(auth()->user());
    }

    public function session(): CashierSession
    {
        return (new CashierSessionService())->currentOrOpen(auth()->user());
    }

    public function accruedCash(): float
    {
        return (new CashierSessionService())->accruedCash($this->session());
    }

    public function logOutflow(): void
    {
        try {
            (new CashierSessionService())->logOutflow($this->session(), (float) $this->outflowAmount, $this->outflowType, $this->outflowNote, auth()->id());

            $this->outflowAmount = null;
            $this->outflowNote = '';
            Notification::make()->title('Outflow logged')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not log outflow')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    public function declareClose(): void
    {
        try {
            (new CashierSessionService())->declareClose($this->session(), (float) $this->declaredClosingCash, auth()->id());

            $this->declaredClosingCash = null;
            Notification::make()->title('Close declared — awaiting supervisor confirmation')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not declare close')->body($e->getMessage())->danger()->persistent()->send();
        }
    }
}
