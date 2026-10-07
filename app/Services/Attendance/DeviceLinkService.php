<?php

namespace App\Services\Attendance;

use App\Models\Attendance\AttendanceDeviceLink;
use App\Models\Attendance\AttendanceDeviceUser;
use App\Models\User;
use App\Support\VenueTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only sanctioned writer of users.biometric_id.
 *
 * Pairing used to be a free-text field on the user form, which meant the
 * pairing had no history, no reason, and no way to tell a correction from a
 * handover. Every link, end and void now goes through here and leaves a row
 * behind.
 *
 * users.biometric_id stays the live "current link" that AttendanceLinker
 * reads — that service is untouched, and setting the column here is what
 * still triggers UserObserver to claim a newly paired badge's past punches.
 */
class DeviceLinkService
{
    /**
     * Pair a badge with a person from a date.
     *
     * Writing users.biometric_id inside the transaction is deliberate: it
     * fires UserObserver, whose sweep of unclaimed punches then joins this
     * transaction and rolls back with it if anything later fails.
     *
     * @throws ValidationException
     */
    public function link(
        AttendanceDeviceUser $deviceUser,
        User $user,
        ?CarbonInterface $effectiveFrom = null,
        ?User $actor = null,
    ): AttendanceDeviceLink {
        $from = $this->localDate($effectiveFrom ?? now());

        $this->assertNotRetired($deviceUser);
        $this->assertNoActiveLink($deviceUser);
        $this->assertUserFree($user, $deviceUser);

        return DB::transaction(function () use ($deviceUser, $user, $from, $actor) {
            $link = AttendanceDeviceLink::create([
                'attendance_device_user_id' => $deviceUser->id,
                'user_id' => $user->id,
                'effective_from' => $from->toDateString(),
                'created_by' => $actor?->id,
            ]);

            $user->forceFill(['biometric_id' => $deviceUser->device_user_id])->save();

            activity('attendance_device_link')
                ->performedOn($link)
                ->causedBy($actor)
                ->withProperties([
                    'device_user_id' => $deviceUser->device_user_id,
                    'user_id' => $user->id,
                    'effective_from' => $from->toDateString(),
                ])
                ->log('Linked device ID '.$deviceUser->device_user_id.' to '.$user->name);

            return $link;
        });
    }

    /**
     * The person left. The link was real and their history stays theirs; the
     * badge is retired so the device can never reissue the ID to somebody who
     * would inherit it.
     */
    public function end(
        AttendanceDeviceLink $link,
        ?CarbonInterface $lastDay = null,
        ?User $actor = null,
        ?string $reason = null,
    ): AttendanceDeviceLink {
        $last = $this->localDate($lastDay ?? now());

        if ($last->lessThan($this->localDate($link->effective_from))) {
            throw ValidationException::withMessages([
                'effective_to' => 'A link cannot end before it began.',
            ]);
        }

        return DB::transaction(function () use ($link, $last, $actor, $reason) {
            $link->forceFill(['effective_to' => $last->toDateString()])->save();

            $deviceUser = $link->deviceUser;
            $deviceUser->forceFill([
                'retired_at' => now(),
                'retired_by' => $actor?->id,
                'retired_reason' => $reason,
            ])->save();

            // Clears the live pairing but deliberately leaves attendance_logs
            // alone: those punches really were made by this person.
            $link->user?->forceFill(['biometric_id' => null])->save();

            activity('attendance_device_link')
                ->performedOn($link)
                ->causedBy($actor)
                ->withProperties(['retired_reason' => $reason, 'effective_to' => $last->toDateString()])
                ->log('Ended and retired device ID '.$deviceUser->device_user_id);

            return $link;
        });
    }

    /**
     * The link was a mistake and should never have existed.
     *
     * Unlike end(), this re-attributes the punches it wrongly claimed —
     * because asserting the link never existed while leaving somebody else's
     * attendance attached to it would be a half-truth that a fine could be
     * raised on.
     *
     * The rewrite is deliberately narrow: only attendance_logs.user_id, only
     * rows carrying this badge, only within the voided link's own dates. It
     * cannot be routed through UserObserver — that path calls
     * AttendanceLinker, which claims only rows where user_id IS NULL and so
     * can never move a punch already attributed to the wrong person.
     *
     * SalaryDeduction rows are never touched. Any legacy late fines over the
     * affected dates come back in the result for a human to deal with:
     * reversing somebody's money automatically, on the strength of a clerical
     * correction, is not a decision this service gets to make.
     *
     * @return array{link: AttendanceDeviceLink, replacement: ?AttendanceDeviceLink, punches_reattributed: int, affected_deductions: \Illuminate\Support\Collection}
     *
     * @throws ValidationException
     */
    public function void(
        AttendanceDeviceLink $link,
        string $reason,
        ?User $actor = null,
        ?User $correctUser = null,
        ?CarbonInterface $correctedFrom = null,
    ): array {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'void_reason' => 'Say why this link is being voided — it is the only record of why the history changed.',
            ]);
        }

        if ($link->isVoided()) {
            throw ValidationException::withMessages([
                'void_reason' => 'That link has already been voided.',
            ]);
        }

        return DB::transaction(function () use ($link, $reason, $actor, $correctUser, $correctedFrom) {
            $deviceUser = $link->deviceUser;
            $wrongUserId = $link->user_id;

            [$from, $to] = $this->punchWindowFor($link);

            $deductions = $this->legacyDeductionsIn($wrongUserId, $from, $to);

            $link->forceFill([
                'voided_at' => now(),
                'voided_by' => $actor?->id,
                'void_reason' => $reason,
            ])->save();

            // Drop the stale pairing before the replacement sets its own, so
            // the unique index on users.biometric_id never sees two holders.
            $link->user?->forceFill(['biometric_id' => null])->save();

            $replacement = null;

            if ($correctUser !== null) {
                $replacement = $this->link(
                    $deviceUser->refresh(),
                    $correctUser,
                    $correctedFrom ?? $link->effective_from,
                    $actor,
                );
            }

            $moved = $this->reattributePunches(
                $deviceUser->device_user_id,
                $wrongUserId,
                $correctUser?->id,
                $from,
                $to,
            );

            activity('attendance_device_link')
                ->performedOn($link)
                ->causedBy($actor)
                ->withProperties([
                    'voided_link_id' => $link->id,
                    'replacement_link_id' => $replacement?->id,
                    'device_user_id' => $deviceUser->device_user_id,
                    'from_user_id' => $wrongUserId,
                    'to_user_id' => $correctUser?->id,
                    'punches_reattributed' => $moved,
                    'window' => [$from->toDateString(), $to->toDateString()],
                    'reason' => $reason,
                ])
                ->log('Voided device link #'.$link->id.' and re-attributed '.$moved.' punch(es)');

            return [
                'link' => $link,
                'replacement' => $replacement,
                'punches_reattributed' => $moved,
                'affected_deductions' => $deductions,
            ];
        });
    }

    /**
     * Create a staff record and link it in one go, for a badge belonging to
     * somebody who is not in Selum at all.
     *
     * One transaction: a user created without its link is an orphan nobody
     * will think to look for, and a link without its user cannot exist.
     */
    public function createStaffAndLink(
        AttendanceDeviceUser $deviceUser,
        string $name,
        ?string $jobTitle = null,
        ?CarbonInterface $effectiveFrom = null,
        ?User $actor = null,
    ): AttendanceDeviceLink {
        $this->assertNotRetired($deviceUser);
        $this->assertNoActiveLink($deviceUser);

        return DB::transaction(function () use ($deviceUser, $name, $jobTitle, $effectiveFrom, $actor) {
            $user = User::create([
                'name' => $name,
                // Unique and required, but this person never signs in, so it
                // is a placeholder rather than an address anyone reads.
                'email' => $this->placeholderEmail($deviceUser),
                'job_title' => $jobTitle,
            ]);

            AttendanceOnlyRoleService::makeAttendanceOnly($user, $actor);

            return $this->link($deviceUser, $user->refresh(), $effectiveFrom, $actor);
        });
    }

    /**
     * Only attendance_logs.user_id, only this badge, only inside the window.
     *
     * @return int rows moved
     */
    private function reattributePunches(
        string $deviceUserId,
        ?int $fromUserId,
        ?int $toUserId,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): int {
        return DB::table('attendance_logs')
            ->where('biometric_id', $deviceUserId)
            ->where('user_id', $fromUserId)
            ->where('punch_time', '>=', $from->startOfDay()->utc())
            ->where('punch_time', '<', $to->addDay()->startOfDay()->utc())
            ->update(['user_id' => $toUserId]);
    }

    /**
     * Legacy ZKTecoController late fines over the affected dates. Reported,
     * never altered — SalaryDeduction write paths are out of bounds here.
     */
    private function legacyDeductionsIn(?int $userId, CarbonImmutable $from, CarbonImmutable $to): \Illuminate\Support\Collection
    {
        if ($userId === null) {
            return collect();
        }

        return DB::table('salary_deductions')
            ->where('user_id', $userId)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')
            ->get();
    }

    /**
     * The dates a voided link could have claimed punches for. An open-ended
     * link runs to today — it was never closed, so it has been claiming
     * punches right up to now.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function punchWindowFor(AttendanceDeviceLink $link): array
    {
        $from = $this->localDate($link->effective_from);
        $to = $link->effective_to !== null
            ? $this->localDate($link->effective_to)
            : $this->localDate(now());

        return [$from, $to->lessThan($from) ? $from : $to];
    }

    private function placeholderEmail(AttendanceDeviceUser $deviceUser): string
    {
        return 'device-'.$deviceUser->device_user_id.'-'.\Illuminate\Support\Str::random(6).'@attendance.local';
    }

    /**
     * @throws ValidationException
     */
    private function assertNotRetired(AttendanceDeviceUser $deviceUser): void
    {
        if ($deviceUser->isRetired()) {
            throw ValidationException::withMessages([
                'attendance_device_user_id' => 'Device ID '.$deviceUser->device_user_id
                    .' was retired and can never be linked again. The terminal reuses IDs, so a new starter on this ID needs a new enrolment.',
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertNoActiveLink(AttendanceDeviceUser $deviceUser): void
    {
        $active = $deviceUser->activeLink();

        if ($active !== null) {
            throw ValidationException::withMessages([
                'user_id' => 'Device ID '.$deviceUser->device_user_id.' is already linked to '
                    .($active->user?->name ?? 'someone').'. End or void that link first.',
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertUserFree(User $user, AttendanceDeviceUser $deviceUser): void
    {
        if (filled($user->biometric_id) && $user->biometric_id !== $deviceUser->device_user_id) {
            throw ValidationException::withMessages([
                'user_id' => $user->name.' already holds device ID '.$user->biometric_id
                    .'. One person cannot be on two badges at once.',
            ]);
        }
    }

    private function localDate(CarbonInterface|string $value): CarbonImmutable
    {
        if (is_string($value)) {
            return CarbonImmutable::parse($value, VenueTime::TIMEZONE)->startOfDay();
        }

        return CarbonImmutable::parse($value->format('Y-m-d'), VenueTime::TIMEZONE)->startOfDay();
    }
}
