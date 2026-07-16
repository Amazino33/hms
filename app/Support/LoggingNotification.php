<?php

namespace App\Support;

use App\Services\ErrorLogRecorder;
use Filament\Notifications\Notification;

/**
 * Every ->danger() notification shown anywhere in the app — whether via the
 * shared UserFeedback service or a raw Notification::make()->danger() call
 * site (still most of them; the notification-fix pass didn't convert every
 * one) — also lands in the System Error Log. Bound in place of the base
 * Filament\Notifications\Notification class (see AppServiceProvider), so
 * this happens for free at every existing call site, no per-file changes.
 */
class LoggingNotification extends Notification
{
    public function send(): static
    {
        if ($this->getStatus() === 'danger') {
            ErrorLogRecorder::recordNotification($this->getTitle(), $this->getBody());
        }

        return parent::send();
    }
}
