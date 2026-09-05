<?php

use App\Livewire\AnnouncementBoard;
use App\Models\AnnouncementAcknowledgement;
use App\Services\AnnouncementService;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

// makeAnnouncement() and announcementStaff() live in tests/Pest.php.

beforeEach(function () {
    $this->service = app(AnnouncementService::class);
    AnnouncementService::flushLiveCache();
});

it('shows an outstanding notice to a panel user and records the signature against them', function () {
    $user = announcementStaff('manager');
    $announcement = makeAnnouncement(['title' => 'Fire drill Tuesday']);
    $this->service->publish($announcement);

    Livewire::actingAs($user)
        ->test(AnnouncementBoard::class, ['context' => 'admin'])
        ->assertSet('notices.0.title', 'Fire drill Tuesday')
        ->call('acknowledge', $announcement->id)
        ->assertSet('notices', []);

    $signature = AnnouncementAcknowledgement::where('announcement_id', $announcement->id)->sole();

    expect($signature->user_id)->toBe($user->id)
        ->and($signature->context)->toBe('admin');
});

it('records a kiosk signature against the staff_pin user, not the web session', function () {
    $waiter = announcementStaff('waiter', 'Floor staff');
    $announcement = makeAnnouncement();
    $this->service->publish($announcement);

    Auth::guard('staff_pin')->login($waiter);

    Livewire::test(AnnouncementBoard::class, ['context' => 'kiosk'])
        ->call('acknowledge', $announcement->id);

    $signature = AnnouncementAcknowledgement::where('announcement_id', $announcement->id)->sole();

    expect($signature->user_id)->toBe($waiter->id)
        ->and($signature->context)->toBe('kiosk');
});

/**
 * The two guards are structurally separate in this app and the board must
 * not quietly bridge them. Someone logged into the admin panel who has NOT
 * tapped a PIN in is not "present" on the kiosk, so a kiosk-context board
 * must show them nothing — otherwise a notice could be signed off by
 * whoever last used a browser rather than the person on the floor.
 */
it('shows nothing on a kiosk board when only the web guard is authenticated', function () {
    $user = announcementStaff('manager');
    $announcement = makeAnnouncement();
    $this->service->publish($announcement);

    Livewire::actingAs($user)
        ->test(AnnouncementBoard::class, ['context' => 'kiosk'])
        ->assertSet('notices', []);

    expect(AnnouncementAcknowledgement::count())->toBe(0);
});

it('refuses to sign a notice that was never addressed to this person', function () {
    $waiter = announcementStaff('waiter');
    announcementStaff('chef', 'Someone else');

    $chefOnly = makeAnnouncement(['audience' => 'roles', 'title' => 'Kitchen only']);
    $chefOnly->syncTargetRoles(['chef']);
    $this->service->publish($chefOnly);

    Livewire::actingAs($waiter)
        ->test(AnnouncementBoard::class, ['context' => 'admin'])
        ->assertSet('notices', [])
        // The id is guessable and arrives straight off the wire, so the
        // component must re-resolve it against this user's own pending set.
        ->call('acknowledge', $chefOnly->id);

    expect(AnnouncementAcknowledgement::where('announcement_id', $chefOnly->id)->count())->toBe(0);
});

it('hides a dismissable notice for the session without recording a signature', function () {
    $user = announcementStaff('manager');
    $announcement = makeAnnouncement(['must_acknowledge' => false]);
    $this->service->publish($announcement);

    Livewire::actingAs($user)
        ->test(AnnouncementBoard::class, ['context' => 'admin'])
        ->assertCount('notices', 1)
        ->call('dismiss', $announcement->id)
        ->assertSet('notices', []);

    expect(AnnouncementAcknowledgement::count())->toBe(0)
        // Still outstanding — "later" must not quietly become "read".
        ->and($this->service->pendingFor($user->fresh())->pluck('id')->all())
        ->toBe([$announcement->id]);
});

/**
 * Removing the overlay in devtools and firing dismiss() by hand must not
 * get anyone past a notice the author marked as blocking.
 */
it('refuses to dismiss a notice that must be acknowledged', function () {
    $user = announcementStaff('manager');
    $announcement = makeAnnouncement(['must_acknowledge' => true]);
    $this->service->publish($announcement);

    Livewire::actingAs($user)
        ->test(AnnouncementBoard::class, ['context' => 'admin'])
        ->call('dismiss', $announcement->id)
        ->assertCount('notices', 1);
});

it('separates blocking notices from dismissable ones and shows only the most severe blocker', function () {
    $user = announcementStaff('manager');

    $sticky = makeAnnouncement(['title' => 'Menu update', 'must_acknowledge' => false, 'severity' => 'info']);
    $warning = makeAnnouncement(['title' => 'Stock count Friday', 'severity' => 'warning']);
    $critical = makeAnnouncement(['title' => 'Gas leak', 'severity' => 'critical']);

    foreach ([$sticky, $warning, $critical] as $announcement) {
        $this->service->publish($announcement);
    }

    $component = Livewire::actingAs($user)->test(AnnouncementBoard::class, ['context' => 'admin']);

    // Three modals stacked at the start of a shift get clicked through
    // without being read, so only one blocks at a time — the worst one.
    expect($component->instance()->blockingNotice['title'])->toBe('Gas leak')
        ->and(collect($component->instance()->stickyNotices)->pluck('title')->all())
        ->toBe(['Menu update']);
});

it('shows nothing at all when there is no live announcement', function () {
    $user = announcementStaff('manager');
    makeAnnouncement(); // left as a draft

    Livewire::actingAs($user)
        ->test(AnnouncementBoard::class, ['context' => 'admin'])
        ->assertSet('notices', []);
});

/**
 * A kiosk is a shared device: one browser session carries across every
 * staff member who taps a PIN into it. If dismissals were stored under a
 * single flat session key, the first waiter tapping "later" would hide
 * the notice from everyone who used that screen after them.
 */
it('does not let one waiter dismissal hide a notice from the next person on the same kiosk', function () {
    $first = announcementStaff('waiter', 'First in');
    $second = announcementStaff('waiter', 'Second in');

    $announcement = makeAnnouncement(['must_acknowledge' => false]);
    $this->service->publish($announcement);

    Auth::guard('staff_pin')->login($first);

    Livewire::test(AnnouncementBoard::class, ['context' => 'kiosk'])
        ->call('dismiss', $announcement->id)
        ->assertSet('notices', []);

    // Same browser session, next person taps in.
    Auth::guard('staff_pin')->login($second);

    Livewire::test(AnnouncementBoard::class, ['context' => 'kiosk'])
        ->assertSet('notices.0.id', $announcement->id);
});

/**
 * The Kitchen Display is the one surface that establishes a staff_pin
 * identity WITHOUT a navigation — kds-board signs the cook in inline, in
 * place. Everywhere else the idle screen redirects into the order screen,
 * which re-mounts this component and picks the notices up for free.
 */
it('picks up a notice when a cook signs in on the kitchen display, with no page change', function () {
    $chef = announcementStaff('chef');
    $announcement = makeAnnouncement();
    $this->service->publish($announcement);

    // The KDS is viewable on a registered device before anyone taps in.
    $board = Livewire::test(AnnouncementBoard::class, ['context' => 'kiosk'])
        ->assertSet('notices', []);

    Auth::guard('staff_pin')->login($chef);

    $board->dispatch('staff-pin-changed')
        ->assertSet('notices.0.id', $announcement->id);
});

it('clears notices off the kitchen display when the cook signs out', function () {
    $chef = announcementStaff('chef');
    $announcement = makeAnnouncement();
    $this->service->publish($announcement);

    Auth::guard('staff_pin')->login($chef);

    $board = Livewire::test(AnnouncementBoard::class, ['context' => 'kiosk'])
        ->assertSet('notices.0.id', $announcement->id);

    Auth::guard('staff_pin')->logout();

    // Otherwise the outgoing cook's notice sits on screen for whoever
    // signs in next.
    $board->dispatch('staff-pin-changed')
        ->assertSet('notices', []);
});

/**
 * What acknowledging must NOT do: navigate, reload, or otherwise disturb
 * whatever the person was looking at. The note clears; the kitchen board
 * (or order screen) underneath it stays exactly where it was.
 */
it('clears the notice on acknowledgement without navigating away from the page underneath', function () {
    $chef = announcementStaff('chef');
    $announcement = makeAnnouncement();
    $this->service->publish($announcement);

    Auth::guard('staff_pin')->login($chef);

    Livewire::test(AnnouncementBoard::class, ['context' => 'kiosk'])
        ->call('acknowledge', $announcement->id)
        ->assertSet('notices', [])
        ->assertNoRedirect();
});
