<?php

use App\Models\AnnouncementAcknowledgement;
use App\Models\AnnouncementRecipient;
use App\Services\AnnouncementService;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->service = app(AnnouncementService::class);
    AnnouncementService::flushLiveCache();
});

// makeAnnouncement() and announcementStaff() live in tests/Pest.php —
// they are shared with AnnouncementBoardTest.

it('freezes the roster at publish time so a later role change cannot rewrite history', function () {
    $waiter = announcementStaff('waiter', 'Ada');
    $chef = announcementStaff('chef', 'Bola');

    $announcement = makeAnnouncement(['audience' => 'roles']);
    $announcement->syncTargetRoles(['waiter']);

    $this->service->publish($announcement);

    expect(AnnouncementRecipient::where('announcement_id', $announcement->id)->pluck('user_id')->all())
        ->toBe([$waiter->id]);

    // Ada is promoted out of waiter afterwards. She was required to read
    // it at the time, so she must stay on the roster.
    $waiter->syncRoles([Role::firstOrCreate(['name' => 'manager'])]);

    expect(AnnouncementRecipient::where('announcement_id', $announcement->id)->pluck('user_id')->all())
        ->toBe([$waiter->id])
        ->and($chef->fresh())->not->toBeNull();
});

it('does not put staff who have left on the roster', function () {
    announcementStaff('waiter', 'Still here');
    $gone = announcementStaff('waiter', 'Left in June');
    $gone->forceFill(['left_at' => now()->subMonth()])->save();

    $announcement = makeAnnouncement();
    $this->service->publish($announcement);

    $rosterNames = AnnouncementRecipient::where('announcement_id', $announcement->id)
        ->with('user')->get()->pluck('user.name')->all();

    expect($rosterNames)->toContain('Still here')
        ->and($rosterNames)->not->toContain('Left in June');
});

it('only shows a role-targeted announcement to those roles', function () {
    $waiter = announcementStaff('waiter');
    $chef = announcementStaff('chef');

    $announcement = makeAnnouncement(['audience' => 'roles']);
    $announcement->syncTargetRoles(['waiter']);
    $this->service->publish($announcement);

    expect($this->service->pendingFor($waiter)->pluck('id')->all())->toBe([$announcement->id])
        ->and($this->service->pendingFor($chef))->toBeEmpty();
});

it('shows a live announcement to someone hired after it was published, flagged as a late join', function () {
    $announcement = makeAnnouncement();
    $this->service->publish($announcement);

    $newHire = announcementStaff('waiter', 'Hired later');

    expect($this->service->pendingFor($newHire)->pluck('id')->all())->toBe([$announcement->id]);

    $roster = AnnouncementRecipient::where('announcement_id', $announcement->id)
        ->where('user_id', $newHire->id)
        ->first();

    expect($roster)->not->toBeNull()
        ->and($roster->is_late_join)->toBeTrue();
});

it('stops showing a draft, a withdrawn notice, an expired one and one scheduled for later', function () {
    $user = announcementStaff('waiter');

    $draft = makeAnnouncement(['title' => 'Draft']);

    $withdrawn = makeAnnouncement(['title' => 'Withdrawn']);
    $this->service->publish($withdrawn);
    $this->service->unpublish($withdrawn);

    $expired = makeAnnouncement(['title' => 'Expired', 'expires_at' => now()->subHour()]);
    $this->service->publish($expired, now()->subDay());

    $scheduled = makeAnnouncement(['title' => 'Scheduled']);
    $this->service->publish($scheduled, now()->addDay());

    $live = makeAnnouncement(['title' => 'Live']);
    $this->service->publish($live);

    AnnouncementService::flushLiveCache();

    expect($this->service->pendingFor($user)->pluck('title')->all())->toBe(['Live'])
        ->and($draft->status())->toBe('draft')
        ->and($withdrawn->status())->toBe('unpublished')
        ->and($expired->status())->toBe('expired')
        ->and($scheduled->status())->toBe('scheduled');
});

it('keeps the roster and the signatures when a notice is withdrawn', function () {
    $user = announcementStaff('waiter');

    $announcement = makeAnnouncement();
    $this->service->publish($announcement);
    $this->service->acknowledge($announcement, $user);

    $this->service->unpublish($announcement);

    expect(AnnouncementRecipient::where('announcement_id', $announcement->id)->count())->toBe(1)
        ->and(AnnouncementAcknowledgement::where('announcement_id', $announcement->id)->count())->toBe(1);
});

/**
 * The scenario this guards against is a staff member double-tapping the
 * button on a laggy kiosk. Two signatures for one person would inflate
 * the read count and make the roster wrong.
 */
it('records exactly one signature no matter how many times the button is tapped', function () {
    $user = announcementStaff('waiter');
    $announcement = makeAnnouncement();
    $this->service->publish($announcement);

    $first = $this->service->acknowledge($announcement, $user, 'kiosk', '10.0.0.1');
    $second = $this->service->acknowledge($announcement, $user, 'kiosk', '10.0.0.1');
    $third = $this->service->acknowledge($announcement, $user, 'admin', '10.0.0.2');

    expect(AnnouncementAcknowledgement::where('announcement_id', $announcement->id)->count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($third->id)->toBe($first->id)
        // The first signature stands — a later tap from elsewhere must not
        // rewrite where or when it was given.
        ->and($third->context)->toBe('kiosk')
        ->and($third->ip_address)->toBe('10.0.0.1');
});

it('drops a signed announcement off the pending list but keeps it in the archive', function () {
    $user = announcementStaff('waiter');
    $announcement = makeAnnouncement();
    $this->service->publish($announcement);

    $this->service->acknowledge($announcement, $user);

    expect($this->service->pendingFor($user))->toBeEmpty()
        ->and($this->service->historyFor($user)->pluck('id')->all())->toBe([$announcement->id]);
});

it('keeps withdrawn and expired notices in the archive so an old policy can be re-read', function () {
    $user = announcementStaff('waiter');

    $withdrawn = makeAnnouncement(['title' => 'Old policy']);
    $this->service->publish($withdrawn);
    $this->service->unpublish($withdrawn);

    expect($this->service->historyFor($user)->pluck('title')->all())->toBe(['Old policy']);
});

it('does not show a kiosk-excluded announcement on the kiosk but still shows it in the panel', function () {
    $user = announcementStaff('manager');
    $announcement = makeAnnouncement(['show_on_kiosk' => false]);
    $this->service->publish($announcement);

    expect($this->service->pendingFor($user, 'admin')->pluck('id')->all())->toBe([$announcement->id])
        ->and($this->service->pendingFor($user, 'kiosk'))->toBeEmpty();
});

it('publishes idempotently rather than duplicating the roster', function () {
    announcementStaff('waiter');
    $announcement = makeAnnouncement();

    $this->service->publish($announcement);
    $this->service->unpublish($announcement);
    $this->service->publish($announcement);

    expect(AnnouncementRecipient::where('announcement_id', $announcement->id)->count())->toBe(1);
});
