<?php

namespace App\Services;

use Filament\Notifications\Notification;

/**
 * The one enforced convention for communicating a blocked action or a
 * failure to the user — admin panel and kiosk alike, since both already
 * share Filament's own notification system (kiosk.blade.php already mounts
 * <livewire:notifications />; this wraps that, it doesn't replace it).
 *
 * blocked()/failed() are always persistent — a message the user needs to
 * act on must never vanish before they've read it. succeeded() auto-
 * dismisses (still tappable to dismiss early, which is Filament's default
 * notification behavior already).
 *
 * Every blocked()/failed() call site is responsible for its own message
 * following cause + remedy: what went wrong, specifically, and what to do
 * about it — not just "action blocked."
 */
class UserFeedback
{
    public static function blocked(string $title, string $body = ''): void
    {
        Notification::make()
            ->title($title)
            ->body($body)
            ->danger()
            ->persistent()
            ->send();
    }

    /**
     * For a genuinely unexpected exception (not a designed guard) caught at
     * an action boundary — the domain code didn't intend for this to
     * happen, so the message stays generic rather than leaking internals.
     */
    public static function failed(
        string $title = 'Action failed',
        string $body = 'Please try again. If this repeats, tell the manager.'
    ): void {
        Notification::make()
            ->title($title)
            ->body($body)
            ->danger()
            ->persistent()
            ->send();
    }

    public static function succeeded(string $title, ?string $body = null): void
    {
        Notification::make()
            ->title($title)
            ->body($body)
            ->success()
            ->duration(4000)
            ->send();
    }
}
