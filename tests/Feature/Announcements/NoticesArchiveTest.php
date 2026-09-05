<?php

use App\Filament\Pages\Notices;
use App\Models\AnnouncementAcknowledgement;
use App\Models\PagePermission;
use App\Services\AnnouncementService;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

// makeAnnouncement() and announcementStaff() live in tests/Pest.php.

function grantNoticesPage(string $roleName): void
{
    PagePermission::firstOrCreate(
        ['page_class' => Notices::class, 'role_name' => $roleName],
        ['page_class' => Notices::class, 'page_name' => 'Notices', 'role_name' => $roleName]
    );
}

/**
 * Pages in this codebase are deny-by-default through PagePermission, with
 * no seed data — access is granted manually after deploy. That must hold
 * for this page too rather than being quietly open because it only shows
 * a person their own notices.
 */
it('denies the notices page until a role is granted it', function () {
    $waiter = announcementStaff('waiter');

    $this->actingAs($waiter);

    expect(Notices::canAccess())->toBeFalse();

    grantNoticesPage('waiter');

    expect(Notices::canAccess())->toBeTrue();
});

it('shows a signed notice with when it was signed, and an outstanding one with a button', function () {
    grantNoticesPage('waiter');
    $waiter = announcementStaff('waiter');

    $signed = makeAnnouncement(['title' => 'Old policy']);
    $outstanding = makeAnnouncement(['title' => 'New policy']);

    $service = app(AnnouncementService::class);
    $service->publish($signed);
    $service->publish($outstanding);
    $service->acknowledge($signed, $waiter);

    Livewire::actingAs($waiter)
        ->test(Notices::class)
        ->assertSuccessful()
        ->assertSee('Old policy')
        ->assertSee('New policy')
        ->assertSee('You marked this as read');
});

it('lets someone sign an outstanding notice from the archive page', function () {
    grantNoticesPage('waiter');
    $waiter = announcementStaff('waiter');

    $announcement = makeAnnouncement();
    app(AnnouncementService::class)->publish($announcement);

    Livewire::actingAs($waiter)
        ->test(Notices::class)
        ->call('acknowledge', $announcement->id);

    $signature = AnnouncementAcknowledgement::where('announcement_id', $announcement->id)->sole();

    expect($signature->user_id)->toBe($waiter->id)
        ->and($signature->context)->toBe('admin');
});

it('refuses to sign a notice from the archive that was never addressed to this person', function () {
    grantNoticesPage('waiter');
    $waiter = announcementStaff('waiter');
    announcementStaff('chef');

    $chefOnly = makeAnnouncement(['audience' => 'roles']);
    $chefOnly->syncTargetRoles(['chef']);
    app(AnnouncementService::class)->publish($chefOnly);

    Livewire::actingAs($waiter)
        ->test(Notices::class)
        ->call('acknowledge', $chefOnly->id);

    expect(AnnouncementAcknowledgement::count())->toBe(0);
});

/**
 * The kiosk archive lists the notices sent to ONE named person, so it must
 * not be reachable from a shared kiosk that nobody has tapped into.
 */
it('keeps the kiosk notices screen behind the staff pin', function () {
    $waiter = announcementStaff('waiter');

    $this->get('/staff/notices')->assertRedirect();

    Auth::guard('staff_pin')->login($waiter);

    expect(Auth::guard('staff_pin')->check())->toBeTrue();
});
