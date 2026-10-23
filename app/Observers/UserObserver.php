<?php

namespace App\Observers;

use App\Models\User;
use App\Services\AttendanceLinker;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;

class UserObserver
{
    /**
     * Handle the User "updated" event.
     */
    public function updated(User $user): void
    {
        // Invalidate per-user sidebar cache so UI changes (name/roles) are reflected.
        Cache::forget('sidebar_html_user_'.$user->id);

        $this->logSensitiveChange($user, array_keys($user->getChanges()), 'updated');
    }

    /**
     * The model's own activity log skips User::SENSITIVE_ATTRIBUTES entirely,
     * so this is the only trace: which groups changed (bank, ID, next of
     * kin, documents, PIN) and who changed them — never a value.
     *
     * @param  list<string>  $attributes
     */
    private function logSensitiveChange(User $user, array $attributes, string $verb): void
    {
        $groups = collect($attributes)
            ->map(fn (string $attribute) => User::SENSITIVE_ATTRIBUTES[$attribute] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($groups === []) {
            return;
        }

        activity('user')
            ->performedOn($user)
            ->causedBy(auth()->user())
            ->event('sensitive_'.$verb)
            ->withProperties(['groups' => $groups])
            ->log('Sensitive details '.$verb.': '.implode(', ', $groups));
    }

    public function deleted(User $user): void
    {
        Cache::forget('sidebar_html_user_'.$user->id);
    }

    public function created(User $user): void
    {
        Cache::forget('sidebar_html_user_'.$user->id);

        $this->logSensitiveChange(
            $user,
            array_keys(array_filter($user->getAttributes(), fn ($value) => filled($value))),
            'set',
        );

        // Handled here rather than in saved() because Laravel only calls
        // syncChanges() on update — after an insert wasChanged() is false for
        // every attribute, so the saved() check below would miss a profile
        // created with the machine ID already typed in.
        $this->linkPastAttendance($user);
    }

    /**
     * Claim past punches the moment a Biometric Machine ID is filled in.
     *
     * The biometric terminal almost always pushes a staff member's punches
     * before anyone gets round to pairing the badge on their profile — it
     * buffers offline and dumps the backlog on its first successful sync. So
     * pairing has to reach backwards, or every newly-paired person starts
     * with a wall of blank-named rows in Attendance Logs that only a manual
     * command could fix.
     *
     * Only when the field actually changed, so an unrelated profile edit does
     * not re-run the sweep; created() covers the insert case.
     */
    public function saved(User $user): void
    {
        if (! $user->wasChanged('biometric_id')) {
            return;
        }

        $this->linkPastAttendance($user);
    }

    private function linkPastAttendance(User $user): void
    {
        if (blank($user->biometric_id)) {
            return;
        }

        $linked = AttendanceLinker::linkFor($user);

        if ($linked === 0) {
            return;
        }

        // Console runs (seeders, hms:link-attendance-logs) have no Livewire
        // request to flash a notification into, and the command reports its
        // own totals anyway.
        if (app()->runningInConsole()) {
            return;
        }

        Notification::make()
            ->success()
            ->title('Past attendance linked')
            ->body("Attached {$linked} earlier punch(es) from machine ID {$user->biometric_id} to {$user->name}.")
            ->send();
    }
}
