<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\AnnouncementAcknowledgement;
use App\Models\AnnouncementRecipient;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AnnouncementService
{
    /**
     * The live-announcement list is identical for every user in the
     * system, so it is cached once globally rather than recomputed per
     * request. This matters: the "anything to read?" check runs on every
     * page load for every logged-in user, and on most days the answer is
     * "nothing" — with this cache that common case costs zero database
     * queries, which is the whole reason this feature needs no polling
     * (see the process-ceiling note in AdminPanelProvider).
     *
     * The short TTL is also what lets a scheduled published_at /
     * expires_at boundary take effect without any write to bust it.
     */
    public const LIVE_CACHE_KEY = 'announcements.live';

    public const LIVE_CACHE_TTL = 60;

    public static function flushLiveCache(): void
    {
        Cache::forget(self::LIVE_CACHE_KEY);
    }

    /**
     * @return Collection<int, Announcement>
     */
    public function liveAnnouncements(): Collection
    {
        return Cache::remember(
            self::LIVE_CACHE_KEY,
            self::LIVE_CACHE_TTL,
            fn () => Announcement::query()->live()->with('targets')->orderByDesc('published_at')->get()
        );
    }

    /**
     * Publish (or re-publish) an announcement and freeze its roster.
     *
     * The roster snapshot happens here, at the moment of publishing,
     * rather than being recomputed on read — see the
     * announcement_recipients migration for why.
     */
    public function publish(Announcement $announcement, ?CarbonInterface $publishAt = null): Announcement
    {
        DB::transaction(function () use ($announcement, $publishAt) {
            $announcement->forceFill([
                'published_at' => $publishAt ?? $announcement->published_at ?? now(),
                'unpublished_at' => null,
            ])->save();

            $this->snapshotRoster($announcement);
        });

        self::flushLiveCache();

        return $announcement->refresh();
    }

    /**
     * Withdraw an announcement from every screen. Deliberately leaves the
     * roster and the signatures untouched — the record of who was asked,
     * and who confirmed, survives the notice being taken down.
     */
    public function unpublish(Announcement $announcement): Announcement
    {
        $announcement->forceFill(['unpublished_at' => now()])->save();

        self::flushLiveCache();

        return $announcement->refresh();
    }

    /**
     * Resolve the audience to actual people and write any roster rows
     * that do not exist yet. Idempotent, so re-publishing never
     * duplicates the roster.
     */
    public function snapshotRoster(Announcement $announcement, bool $lateJoin = false): int
    {
        $userIds = $this->audienceUserIds($announcement);

        if ($userIds->isEmpty()) {
            return 0;
        }

        $existing = AnnouncementRecipient::query()
            ->where('announcement_id', $announcement->id)
            ->whereIn('user_id', $userIds)
            ->pluck('user_id');

        $missing = $userIds->diff($existing);

        if ($missing->isEmpty()) {
            return 0;
        }

        $now = now();

        AnnouncementRecipient::insert(
            $missing->map(fn ($userId) => [
                'announcement_id' => $announcement->id,
                'user_id' => $userId,
                'is_late_join' => $lateJoin,
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all()
        );

        return $missing->count();
    }

    /**
     * Who this announcement is for, right now. Staff who have left are
     * excluded, using the same `left_at` rule payroll compilation uses.
     *
     * @return Collection<int, int>
     */
    public function audienceUserIds(Announcement $announcement): Collection
    {
        $query = User::query()->whereNull('left_at');

        if ($announcement->audience === 'roles') {
            $roles = $announcement->targets->pluck('role_name')->all();

            if ($roles === []) {
                return collect();
            }

            $query->whereHas('roles', fn ($q) => $q->whereIn('name', $roles));
        }

        return $query->pluck('id');
    }

    /**
     * Does this announcement apply to this user? Evaluated in memory
     * against the already-loaded live list, so it costs no queries.
     */
    public function appliesTo(Announcement $announcement, User $user): bool
    {
        if ($user->left_at !== null) {
            return false;
        }

        if ($announcement->audience === 'all') {
            return true;
        }

        $roles = $announcement->targets->pluck('role_name')->all();

        return $roles !== [] && $user->hasAnyRole($roles);
    }

    /**
     * Everything this user still has to sign, for the surface they are
     * looking at ('admin' or 'kiosk').
     *
     * @return Collection<int, Announcement>
     */
    public function pendingFor(User $user, string $context = 'admin'): Collection
    {
        $live = $this->liveAnnouncements();

        // The common case: nothing published. Zero queries from here on.
        if ($live->isEmpty()) {
            return collect();
        }

        $applicable = $live->filter(fn (Announcement $a) => $this->appliesTo($a, $user));

        if ($applicable->isEmpty()) {
            return collect();
        }

        $ids = $applicable->pluck('id')->all();

        $acknowledged = AnnouncementAcknowledgement::query()
            ->where('user_id', $user->id)
            ->whereIn('announcement_id', $ids)
            ->pluck('announcement_id');

        // Anyone who matches a live announcement but has no roster row
        // either joined, or was moved into a targeted role, after it went
        // out. Recorded now and flagged, so the roster never implies they
        // were on staff when it was published.
        $this->backfillLateJoiner($user, $applicable);

        $pending = $applicable->reject(fn (Announcement $a) => $acknowledged->contains($a->id));

        // A notice aimed at the office has no business interrupting a
        // shared kiosk mid-service; this is the author's show_on_kiosk call.
        if ($context === 'kiosk') {
            $pending = $pending->filter(fn (Announcement $a) => $a->show_on_kiosk);
        }

        return $pending->values();
    }

    /**
     * @param  Collection<int, Announcement>  $applicable
     */
    private function backfillLateJoiner(User $user, Collection $applicable): void
    {
        $ids = $applicable->pluck('id');

        $onRoster = AnnouncementRecipient::query()
            ->where('user_id', $user->id)
            ->whereIn('announcement_id', $ids->all())
            ->pluck('announcement_id');

        $missing = $ids->diff($onRoster);

        if ($missing->isEmpty()) {
            return;
        }

        foreach ($missing as $announcementId) {
            // firstOrCreate rather than a bulk insert: two tabs loading at
            // the same moment would otherwise race on the unique index.
            AnnouncementRecipient::firstOrCreate(
                ['announcement_id' => $announcementId, 'user_id' => $user->id],
                ['is_late_join' => true],
            );
        }
    }

    /**
     * Record a signature. Idempotent by design — a double tap on a laggy
     * kiosk must produce one signature, and the unique index is what
     * guarantees that rather than a check-then-insert race.
     */
    public function acknowledge(
        Announcement $announcement,
        User $user,
        string $context = 'admin',
        ?string $ipAddress = null,
    ): AnnouncementAcknowledgement {
        $existing = AnnouncementAcknowledgement::query()
            ->where('announcement_id', $announcement->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        // Signing implies they were required to read it, so make sure the
        // roster reflects that even for a late joiner who reached the
        // button before the backfill ran.
        AnnouncementRecipient::firstOrCreate(
            ['announcement_id' => $announcement->id, 'user_id' => $user->id],
            ['is_late_join' => true],
        );

        try {
            return AnnouncementAcknowledgement::create([
                'announcement_id' => $announcement->id,
                'user_id' => $user->id,
                'acknowledged_at' => now(),
                'context' => $context,
                'ip_address' => $ipAddress,
            ]);
        } catch (QueryException $e) {
            // Lost the race against another tab: the other insert won and
            // the signature exists, which is the outcome we wanted anyway.
            $winner = AnnouncementAcknowledgement::query()
                ->where('announcement_id', $announcement->id)
                ->where('user_id', $user->id)
                ->first();

            if ($winner) {
                return $winner;
            }

            throw $e;
        }
    }

    /**
     * Everything this user was ever sent, signed or not, newest first —
     * the archive behind the Notices page. Includes expired and withdrawn
     * notices, because being able to re-read an old policy is the point.
     *
     * @return Collection<int, Announcement>
     */
    public function historyFor(User $user): Collection
    {
        return Announcement::query()
            ->whereNotNull('published_at')
            ->whereHas('recipients', fn ($q) => $q->where('user_id', $user->id))
            ->with(['acknowledgements' => fn ($q) => $q->where('user_id', $user->id)])
            ->orderByDesc('published_at')
            ->get();
    }
}
