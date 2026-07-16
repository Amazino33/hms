<?php

namespace App\Filament\Pages;

use App\Services\ErrorLogRecorder;
use App\Services\PermissionService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use UnitEnum;

/**
 * Reads storage/logs/app-errors.log directly (see ErrorLogRecorder) rather
 * than a database table — this page's entire purpose is to still show what
 * went wrong even when the error IS the database being unreachable, which
 * a DB-backed log obviously couldn't do.
 */
class SystemErrorLog extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static string|UnitEnum|null $navigationGroup = 'System Management';

    protected static ?string $navigationLabel = 'Error Log';

    protected static ?string $title = 'System Error Log';

    protected static ?int $navigationSort = 101;

    protected string $view = 'filament.pages.system-error-log';

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    public ?int $expandedIndex = null;

    public function getViewData(): array
    {
        return [
            'entries' => ErrorLogRecorder::recent(200),
        ];
    }

    public function toggleExpand(int $index): void
    {
        $this->expandedIndex = $this->expandedIndex === $index ? null : $index;
    }

    public function clearLog(): void
    {
        ErrorLogRecorder::clear();
        $this->expandedIndex = null;

        Notification::make()->title('Error log cleared')->success()->send();
    }
}
