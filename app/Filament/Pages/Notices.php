<?php

namespace App\Filament\Pages;

use App\Models\Announcement;
use App\Services\AnnouncementService;
use App\Services\PermissionService;
use BackedEnum;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

/**
 * The archive behind the notice board: every announcement this person was
 * sent, signed or not, including expired and withdrawn ones.
 *
 * A Page, so it is gated through PagePermission / PermissionService
 * (deny-by-default, granted per role in the Page Permissions Manager) —
 * NOT through the Shield policy that gates AnnouncementResource. Those are
 * two different mechanisms in this codebase and the resource is the
 * authoring side: being allowed to re-read your own notices must not
 * imply being allowed to write one.
 */
class Notices extends \Filament\Pages\Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationLabel = 'Notices';

    protected string $view = 'filament.pages.notices';

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    public function acknowledge(int $announcementId): void
    {
        $user = Auth::user();

        if (! $user) {
            return;
        }

        // Only ever from this user's own pending set — the same rule the
        // notice board itself applies, so the page cannot be used to sign
        // something that was never addressed to you.
        $announcement = app(AnnouncementService::class)
            ->pendingFor($user, 'admin')
            ->firstWhere('id', $announcementId);

        if (! $announcement) {
            Notification::make()
                ->title('Nothing to sign')
                ->body('That notice is no longer showing, or you have already marked it as read.')
                ->warning()
                ->send();

            return;
        }

        app(AnnouncementService::class)->acknowledge($announcement, $user, 'admin', request()->ip());

        Notification::make()
            ->title('Noted — thank you')
            ->body('"'.$announcement->title.'" has been marked as read.')
            ->success()
            ->send();
    }

    protected function getViewData(): array
    {
        $user = Auth::user();

        $notices = $user
            ? app(AnnouncementService::class)->historyFor($user)
            : collect();

        return [
            'notices' => $notices,
            'pendingIds' => $user
                ? app(AnnouncementService::class)->pendingFor($user, 'admin')->pluck('id')->all()
                : [],
        ];
    }
}
