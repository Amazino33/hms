<?php

namespace App\Services;

use App\Models\MenuItem;
use App\Models\MenuItemAvailabilityLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The KDS's quick sold-out switch (Phase 1A). It writes the very same
 * menu_items.available_for_sale column the admin menu-item form edits —
 * one source of truth — and logs every flip it makes, append-only.
 *
 * Who may flip it is enforced HERE (D13), not by the caller: anyone with a
 * PIN can sign in to /kds, but only kitchen staff, supervisors and
 * managers may change what's available. There is no "supervisor" role —
 * the codebase's supervisor check is manager/admin/super_admin.
 */
class MenuAvailabilityService
{
    public const ALLOWED_ROLES = ['chef', 'manager', 'admin', 'super_admin'];

    /**
     * @return bool whether anything changed (setting the current value again is a no-op and not logged)
     *
     * @throws \Exception when the actor isn't allowed to change availability
     */
    public function set(MenuItem $item, bool $available, User $actor): bool
    {
        $this->assertAllowed($actor);

        return DB::transaction(function () use ($item, $available, $actor) {
            $item = MenuItem::lockForUpdate()->findOrFail($item->id);

            return $this->flip($item, $available, $actor);
        });
    }

    /**
     * Start-of-day reset: every sold-out menu item back to available, one
     * log row per item actually changed.
     *
     * @return int how many items were changed
     */
    public function resetAllToAvailable(User $actor): int
    {
        $this->assertAllowed($actor);

        return DB::transaction(function () use ($actor) {
            return MenuItem::where('available_for_sale', false)
                ->lockForUpdate()
                ->get()
                ->filter(fn (MenuItem $item) => $this->flip($item, true, $actor))
                ->count();
        });
    }

    public static function canChange(User $actor): bool
    {
        return $actor->hasRole(self::ALLOWED_ROLES);
    }

    private function assertAllowed(User $actor): void
    {
        if (! self::canChange($actor)) {
            throw new \Exception('Only kitchen staff or a manager can mark food sold out or available.');
        }
    }

    private function flip(MenuItem $item, bool $available, User $actor): bool
    {
        $current = (bool) $item->available_for_sale;

        if ($current === $available) {
            return false;
        }

        $item->update(['available_for_sale' => $available]);

        MenuItemAvailabilityLog::create([
            'menu_item_id' => $item->id,
            'from' => $current,
            'to' => $available,
            'user_id' => $actor->id,
        ]);

        return true;
    }
}
