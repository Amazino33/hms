<?php

namespace App\Filament\Pages;

use App\Models\CountSession;
use App\Models\Shift;
use App\Models\User;
use App\Services\CountSessionService;
use App\Services\InventoryService;
use App\Services\PermissionService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Computed;
use UnitEnum;

/**
 * A bartender/chef only needs to COUNT — picking a session type, warehouse,
 * and outgoing custodian from an admin-style form is unnecessary friction
 * (and exposes them to a page meant for whoever administers the whole
 * count-session system). This page infers everything from who's logged in
 * and whether they currently have an active shift, and only ever asks one
 * real question: who are you handing over to (if anyone).
 */
class MyCount extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static string|UnitEnum|null $navigationGroup = 'Inventory';
    protected static ?string $navigationLabel = 'Count Handover';
    protected static ?string $title = 'My Handover Count';
    protected string $view = 'filament.pages.my-count';

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    private const ROLE_TYPE = [
        'bartender' => 'bar_handover',
        'chef' => 'kitchen_handover',
    ];

    public ?int $incomingUserId = null;

    /**
     * Only meaningful when hasActiveShift() is true. False = a normal
     * handover (incomingUserId becomes the new custodian, gets a shift).
     * True = closing count for the end of the day — incomingUserId is just
     * a witness confirming the count; my shift ends and nobody's starts.
     */
    public bool $isClosing = false;

    public function myRole(): ?string
    {
        foreach (self::ROLE_TYPE as $role => $type) {
            if (auth()->user()->hasRole($role)) {
                return $role;
            }
        }

        return null;
    }

    private function myType(): ?string
    {
        $role = $this->myRole();

        return $role ? self::ROLE_TYPE[$role] : null;
    }

    private function myWarehouseId(): ?int
    {
        return match ($this->myRole()) {
            'bartender' => InventoryService::getBarWarehouseId(),
            'chef' => InventoryService::getKitchenWarehouseId(),
            default => null,
        };
    }

    /**
     * True if I'm currently the custodian on duty — meaning starting a new
     * count now is a HANDOVER (I name who I'm handing to). False means
     * there's nobody to hand over from, so it's a solo OPENING count (I'm
     * simply the one starting the day).
     */
    public function hasActiveShift(): bool
    {
        $role = $this->myRole();

        if (!$role) {
            return false;
        }

        return Shift::where('user_id', auth()->id())->ofType($role)->activeNonStale($role)->exists();
    }

    /**
     * A count I already have open (as outgoing or incoming) for my
     * warehouse — jump straight back into it instead of offering to start
     * a second, redundant one.
     */
    #[Computed]
    public function myOpenSession(): ?CountSession
    {
        $type = $this->myType();

        if (!$type) {
            return null;
        }

        return CountSession::where('type', $type)
            ->whereIn('status', ['counting', 'pending_review'])
            ->where(function ($query) {
                $query->where('outgoing_user_id', auth()->id())
                    ->orWhere('incoming_user_id', auth()->id());
            })
            ->latest('opened_at')
            ->first();
    }

    #[Computed]
    public function candidateIncomingUsers()
    {
        $role = $this->myRole();

        if (!$role) {
            return collect();
        }

        return User::role($role)->where('id', '!=', auth()->id())->orderBy('name')->get();
    }

    public function startCount(): void
    {
        $role = $this->myRole();
        $type = $this->myType();
        $warehouseId = $this->myWarehouseId();

        if (!$role || !$type || !$warehouseId) {
            Notification::make()->title('Your account is not set up as a bartender or chef')->danger()->send();
            return;
        }

        $isHandover = $this->hasActiveShift();

        if ($isHandover && !$this->incomingUserId) {
            Notification::make()->title($this->isClosing
                ? 'Choose a second person to confirm your closing count first'
                : 'Choose who you are handing over to first')->warning()->send();
            return;
        }

        try {
            $session = (new CountSessionService())->openSession(
                $type,
                $warehouseId,
                auth()->id(),
                $isHandover ? auth()->id() : null,
                $isHandover ? $this->incomingUserId : auth()->id(),
                isClosing: $isHandover && $this->isClosing,
            );

            $this->redirect("/admin/count-session-detail?session_id={$session->id}");
        } catch (\Exception $e) {
            Notification::make()->title('Could not start count')->body($e->getMessage())->danger()->send();
        }
    }

    public function goToOpenSession(): void
    {
        if ($session = $this->myOpenSession) {
            $this->redirect("/admin/count-session-detail?session_id={$session->id}");
        }
    }
}
