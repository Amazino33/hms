<?php

namespace App\Livewire;

use App\Models\Announcement;
use App\Models\User;
use App\Services\AnnouncementService;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The staff notice board, mounted once per layout: in the admin panel via
 * a BODY_END render hook, and in the kiosk layout for PIN-authenticated
 * floor staff. One component serves both because a signature means the
 * same thing wherever it is given — only the guard it reads and the
 * surface it renders on differ.
 */
class AnnouncementBoard extends Component
{
    /**
     * Which surface this instance is running on: 'admin' (web guard) or
     * 'kiosk' (staff_pin guard). Locked because it selects the guard the
     * component authenticates against and is stamped onto every signature
     * — a client must not be able to flip it and file an office
     * confirmation for something tapped on the floor.
     */
    #[Locked]
    public string $context = 'admin';

    /** @var array<int, array<string, mixed>> */
    public array $notices = [];

    /**
     * Notices the user has waved away for this session. Only non-blocking
     * ones can land here, and it is session state rather than a database
     * column on purpose: "not now" must not survive to the next login,
     * otherwise an unsigned notice quietly disappears forever.
     *
     * Keyed by user id, because a kiosk is a SHARED device: one browser
     * session carries across every staff member who taps a PIN into it.
     * A single flat key would let one waiter's "later" hide the notice
     * from the next person on that same screen.
     */
    private function dismissedKey(User $user): string
    {
        return 'announcements.dismissed.'.$user->id;
    }

    /**
     * Unlike ShiftManager, this deliberately does its work in mount()
     * rather than deferring to wire:init. The check is served from a
     * cached, process-wide live list, so on the ordinary day where
     * nothing is published it costs zero queries — cheaper inline than
     * paying for a whole extra HTTP request and PHP process to find out
     * there was nothing to show. That process ceiling is the same reason
     * this component never polls.
     */
    public function mount(string $context = 'admin'): void
    {
        $this->context = in_array($context, ['admin', 'kiosk'], true) ? $context : 'admin';

        $this->refreshNotices();
    }

    /**
     * Most surfaces establish the staff_pin identity through a navigation
     * (the idle screen redirects into the order screen after a correct
     * PIN), which re-mounts this component and picks the notices up for
     * free. The Kitchen Display does NOT: it signs the cook in and out
     * inline, in place, with no page change at all. Without this listener
     * a chef could tap in on the KDS and never see a notice until someone
     * happened to reload the board.
     */
    #[On('staff-pin-changed')]
    public function staffPinChanged(): void
    {
        $this->refreshNotices();
    }

    public function acknowledge(int $announcementId): void
    {
        $user = $this->actor();

        if (! $user) {
            return;
        }

        // Re-resolve from the user's own pending set rather than trusting
        // the id off the wire — otherwise anyone could file a signature
        // against a notice that was never addressed to them.
        $announcement = $this->resolvePending($user, $announcementId);

        if (! $announcement) {
            $this->refreshNotices();

            return;
        }

        app(AnnouncementService::class)->acknowledge(
            $announcement,
            $user,
            $this->context,
            request()->ip(),
        );

        $this->refreshNotices();

        Notification::make()
            ->title('Noted — thank you')
            ->body('"'.$announcement->title.'" has been marked as read.')
            ->success()
            ->send();
    }

    /**
     * Hide a non-blocking notice until the next login. A notice the author
     * marked must_acknowledge is refused here, not merely hidden in the
     * markup, so removing the overlay in devtools achieves nothing.
     */
    public function dismiss(int $announcementId): void
    {
        $user = $this->actor();

        if (! $user) {
            return;
        }

        $announcement = $this->resolvePending($user, $announcementId);

        if (! $announcement || $announcement->must_acknowledge) {
            return;
        }

        $dismissed = session()->get($this->dismissedKey($user), []);
        $dismissed[] = $announcement->id;
        session()->put($this->dismissedKey($user), array_values(array_unique($dismissed)));

        $this->refreshNotices();
    }

    private function refreshNotices(): void
    {
        $user = $this->actor();

        if (! $user) {
            $this->notices = [];

            return;
        }

        $dismissed = session()->get($this->dismissedKey($user), []);

        $this->notices = app(AnnouncementService::class)
            ->pendingFor($user, $this->context)
            ->reject(fn (Announcement $a) => ! $a->must_acknowledge && in_array($a->id, $dismissed, true))
            ->map(fn (Announcement $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'body' => $a->body,
                'severity' => $a->severity,
                'must_acknowledge' => $a->must_acknowledge,
                'published_at' => $a->published_at?->format('M j, Y g:ia'),
                'author' => $a->creator?->name,
            ])
            ->values()
            ->all();
    }

    private function resolvePending(User $user, int $announcementId): ?Announcement
    {
        return app(AnnouncementService::class)
            ->pendingFor($user, $this->context)
            ->firstWhere('id', $announcementId);
    }

    /**
     * The two guards never mix, by design (see CLAUDE.md): the admin panel
     * only ever knows about `web`, and the kiosk only ever about
     * `staff_pin`. Reading the guard that matches this surface — instead
     * of falling back from one to the other — is what keeps that true and
     * makes the recorded context honest.
     */
    private function actor(): ?User
    {
        $user = $this->context === 'kiosk'
            ? Auth::guard('staff_pin')->user()
            : Auth::guard('web')->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * The one notice that blocks the screen, if any. Only the most severe
     * outstanding one is shown at a time — stacking three modals on a
     * waiter at the start of a shift guarantees all three get clicked
     * through without being read.
     */
    public function getBlockingNoticeProperty(): ?array
    {
        $blocking = array_values(array_filter(
            $this->notices,
            fn (array $n) => $n['must_acknowledge'],
        ));

        if ($blocking === []) {
            return null;
        }

        $rank = ['critical' => 0, 'warning' => 1, 'info' => 2];

        usort($blocking, fn (array $a, array $b) => ($rank[$a['severity']] ?? 3) <=> ($rank[$b['severity']] ?? 3));

        return $blocking[0];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getStickyNoticesProperty(): array
    {
        return array_values(array_filter(
            $this->notices,
            fn (array $n) => ! $n['must_acknowledge'],
        ));
    }

    public function render()
    {
        return view('livewire.announcement-board');
    }
}
