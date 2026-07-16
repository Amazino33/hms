<?php

namespace App\Services;

use Illuminate\Support\Facades\Request;
use Throwable;

/**
 * Deliberately file-based, not a database table: the whole point of this
 * log is to still capture the error when the database itself is down (a
 * PDOException "Connection refused" is exactly the kind of thing that must
 * show up here) — writing to storage/ instead of the DB is what makes that
 * possible. One JSON object per line so the admin page can parse it without
 * pulling in a log-parsing library.
 */
class ErrorLogRecorder
{
    private const LOG_RELATIVE_PATH = 'logs/app-errors.log';

    private const MAX_LINES = 2000;

    /**
     * Every ->danger() notification shown to a user (see LoggingNotification)
     * — no exception object to draw a stack trace from here, just the
     * title/body the user actually saw, which is exactly what was asked
     * for: "have it show what shows in the notifications".
     */
    public static function recordNotification(?string $title, ?string $body): void
    {
        $userId = null;
        try {
            $userId = auth()->id();
        } catch (Throwable) {
        }

        $url = null;
        $method = null;
        try {
            $url = Request::fullUrl();
            $method = Request::method();
        } catch (Throwable) {
        }

        $entry = [
            'time' => now()->toDateTimeString(),
            'source' => 'notification',
            'class' => 'Notification',
            'message' => $title ?? '(no title)',
            'body' => $body,
            'code' => null,
            'file' => null,
            'line' => null,
            'url' => $url,
            'method' => $method,
            'user_id' => $userId,
            'trace' => null,
        ];

        self::write($entry);
    }

    public static function record(Throwable $e): void
    {
        // auth()->id() and the request facade can themselves touch the
        // database (session/auth guard) or simply be unavailable outside
        // an HTTP context (queue workers, console) — never let recording
        // the error throw a second exception on top of the first.
        $userId = null;
        try {
            $userId = auth()->id();
        } catch (Throwable) {
        }

        $url = null;
        $method = null;
        try {
            $url = Request::fullUrl();
            $method = Request::method();
        } catch (Throwable) {
        }

        $entry = [
            'time' => now()->toDateTimeString(),
            'source' => 'exception',
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'body' => null,
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'url' => $url,
            'method' => $method,
            'user_id' => $userId,
            'trace' => collect($e->getTrace())
                ->take(15)
                ->map(fn (array $frame) => sprintf(
                    '%s:%s %s%s%s',
                    $frame['file'] ?? '[internal]',
                    $frame['line'] ?? '?',
                    $frame['class'] ?? '',
                    $frame['type'] ?? '',
                    $frame['function'] ?? ''
                ))
                ->implode("\n"),
        ];

        self::write($entry);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function write(array $entry): void
    {
        try {
            $path = storage_path(self::LOG_RELATIVE_PATH);
            file_put_contents($path, json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
            self::trimIfTooLarge($path);
        } catch (Throwable) {
            // Recording the error must never itself become an error.
        }
    }

    /**
     * @return array<int, array<string, mixed>> newest first
     */
    public static function recent(int $limit = 200): array
    {
        $path = storage_path(self::LOG_RELATIVE_PATH);

        if (! is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = array_slice($lines, -$limit);

        return collect(array_reverse($lines))
            ->map(fn (string $line) => json_decode($line, true))
            ->filter(fn ($entry) => is_array($entry))
            ->values()
            ->all();
    }

    public static function clear(): void
    {
        $path = storage_path(self::LOG_RELATIVE_PATH);

        if (is_file($path)) {
            file_put_contents($path, '', LOCK_EX);
        }
    }

    private static function trimIfTooLarge(string $path): void
    {
        if (filesize($path) < 5 * 1024 * 1024) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        if (count($lines) <= self::MAX_LINES) {
            return;
        }

        $lines = array_slice($lines, -self::MAX_LINES);
        file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX);
    }
}
