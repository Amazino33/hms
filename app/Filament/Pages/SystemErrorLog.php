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

    /**
     * Keyword categories a raw log entry's message/body gets tagged with,
     * checked in order — the first match wins. Stock-outs and insufficient-
     * stock failures (POS sale rejections, transfer receive failures) are by
     * far the noisiest, most repetitive entries in this log day to day, and
     * were previously indistinguishable from any other notification at a
     * glance — this exists purely to make them visually scannable without
     * expanding every row.
     *
     * @var array<string, string[]>
     */
    private const CATEGORY_KEYWORDS = [
        'stock' => ['stock', 'warehouse', 'insufficient', 'inventory'],
    ];

    public static function categoryFor(array $entry): ?string
    {
        $haystack = mb_strtolower(($entry['message'] ?? '').' '.($entry['body'] ?? ''));

        foreach (self::CATEGORY_KEYWORDS as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    return $category;
                }
            }
        }

        return null;
    }

    public function getViewData(): array
    {
        return [
            'entries' => $this->groupedEntries(),
        ];
    }

    /**
     * Collapses consecutive entries (adjacent in the newest-first list)
     * that share the same message and body into a single row carrying an
     * occurrence count and the first/last time it happened — the exact
     * shape of the noise a repeatedly-tapped out-of-stock sale attempt
     * produces (four identical rows a few seconds apart, burying whatever
     * came before them). Deliberately only merges adjacent duplicates, not
     * the same message recurring non-consecutively — collapsing across
     * unrelated intervening entries would lose their relative timing.
     *
     * The source list is newest-first, so within a run: 'time' (the
     * original field, left untouched) and 'last_time' both hold the most
     * recent occurrence, fixed at the moment the group starts; 'first_time'
     * is overwritten on every subsequent merge, ending up as the oldest —
     * i.e. when this run of repeats actually began.
     *
     * @return array<int, array<string, mixed>>
     */
    private function groupedEntries(): array
    {
        $grouped = [];

        foreach (ErrorLogRecorder::recent(200) as $entry) {
            $entry['category'] = self::categoryFor($entry);
            $lastIndex = array_key_last($grouped);
            $last = $lastIndex !== null ? $grouped[$lastIndex] : null;

            if ($last !== null
                && ($last['message'] ?? null) === ($entry['message'] ?? null)
                && ($last['body'] ?? null) === ($entry['body'] ?? null)
                && ($last['source'] ?? null) === ($entry['source'] ?? null)
            ) {
                $grouped[$lastIndex]['occurrences']++;
                $grouped[$lastIndex]['first_time'] = $entry['time'];

                continue;
            }

            $entry['occurrences'] = 1;
            $entry['first_time'] = $entry['time'];
            $entry['last_time'] = $entry['time'];
            $grouped[] = $entry;
        }

        return array_values($grouped);
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
