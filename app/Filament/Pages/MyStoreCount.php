<?php

namespace App\Filament\Pages;

use App\Models\CountSession;
use App\Models\WareHouse;
use App\Services\CountSessionService;
use App\Services\PermissionService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Computed;
use UnitEnum;

/**
 * The storekeeper's equivalent of MyCount — but much simpler, since a
 * store count has no successor to name: nobody is handed anything, there's
 * no incoming/witness picker, just "start" or "continue". Unlike
 * bar/kitchen, this isn't tied to a shift at all (the storekeeper isn't a
 * PIN-identified kiosk role with a shift-typed clock-in the way a
 * bartender/chef is), so this page only ever asks one thing: is there
 * already a count in progress, or should a new one start.
 */
class MyStoreCount extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static string|UnitEnum|null $navigationGroup = 'Inventory';
    protected static ?string $navigationLabel = 'My Store Count';
    protected static ?string $title = 'My Store Count';
    protected string $view = 'filament.pages.my-store-count';

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    private function warehouseId(): ?int
    {
        return (WareHouse::where('type', 'storage')->first() ?? WareHouse::first())?->id;
    }

    /**
     * A store count I already have open — jump straight back into it
     * instead of offering to start a second, redundant one. Solo sessions
     * are never named by outgoing/incoming_user_id, only opened_by.
     */
    #[Computed]
    public function myOpenSession(): ?CountSession
    {
        return CountSession::where('type', 'main_store_stocktake')
            ->where('opened_by', auth()->id())
            ->whereIn('status', ['counting', 'declared', 'pending_review'])
            ->latest('opened_at')
            ->first();
    }

    public function startCount(): void
    {
        $warehouseId = $this->warehouseId();

        if (!$warehouseId) {
            Notification::make()->title('No storage warehouse is configured')->danger()->persistent()->send();
            return;
        }

        try {
            $session = (new CountSessionService())->openSession(
                'main_store_stocktake',
                $warehouseId,
                auth()->id(),
            );

            $this->redirect("/admin/count-session-detail?session_id={$session->id}");
        } catch (\Exception $e) {
            Notification::make()->title('Could not start count')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    public function goToOpenSession(): void
    {
        if ($session = $this->myOpenSession) {
            $this->redirect("/admin/count-session-detail?session_id={$session->id}");
        }
    }
}
