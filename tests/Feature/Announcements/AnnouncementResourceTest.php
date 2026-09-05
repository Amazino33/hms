<?php

use App\Filament\Resources\Announcements\Pages\CreateAnnouncement;
use App\Filament\Resources\Announcements\Pages\EditAnnouncement;
use App\Filament\Resources\Announcements\Pages\ListAnnouncements;
use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Services\AnnouncementService;
use Database\Seeders\ShieldSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// makeAnnouncement() and announcementStaff() live in tests/Pest.php.

/**
 * Creates the permissions `shield:generate` produces on deploy and grants
 * them to a role — additively, the way a live system must be changed.
 */
function grantAnnouncementPermissions(string $roleName): void
{
    $role = Role::firstOrCreate(['name' => $roleName]);

    foreach (['ViewAny', 'View', 'Create', 'Update', 'Delete'] as $ability) {
        $role->givePermissionTo(
            Permission::firstOrCreate(['name' => "{$ability}:Announcement", 'guard_name' => 'web'])
        );
    }
}

/**
 * The failure this guards against is the one CLAUDE.md warns about: a new
 * Filament Resource with no policy is open to every authenticated panel
 * user. For a tool that puts a blocking modal on every screen in the
 * building, that would be the wrong default.
 */
it('denies the resource to a panel user whose role was never granted it', function () {
    $this->seed(ShieldSeeder::class);

    $waiter = announcementStaff('waiter');

    $this->actingAs($waiter)
        ->get('/admin/announcements')
        ->assertForbidden();
});

it('lets a role that was granted the permission open the resource', function () {
    $this->seed(ShieldSeeder::class);
    grantAnnouncementPermissions('manager');

    $manager = announcementStaff('manager');

    Livewire::actingAs($manager)
        ->test(ListAnnouncements::class)
        ->assertSuccessful();
});

it('creates a draft that reaches nobody until it is published, stamped with its author', function () {
    $this->seed(ShieldSeeder::class);
    grantAnnouncementPermissions('manager');

    $manager = announcementStaff('manager');
    $waiter = announcementStaff('waiter');

    Livewire::actingAs($manager)
        ->test(CreateAnnouncement::class)
        ->fillForm([
            'title' => 'New uniform policy',
            'body' => '<p>Black shirts from Monday.</p>',
            'severity' => 'warning',
            'audience' => 'roles',
            'target_roles' => ['waiter'],
            'must_acknowledge' => true,
            'show_on_kiosk' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $announcement = Announcement::where('title', 'New uniform policy')->sole();

    expect($announcement->created_by)->toBe($manager->id)
        ->and($announcement->status())->toBe('draft')
        ->and($announcement->targets->pluck('role_name')->all())->toBe(['waiter'])
        // Nothing is on a screen and nobody is on the roster yet.
        ->and(AnnouncementRecipient::where('announcement_id', $announcement->id)->count())->toBe(0)
        ->and(app(AnnouncementService::class)->pendingFor($waiter))->toBeEmpty();
});

it('freezes the roster when the publish action runs', function () {
    $this->seed(ShieldSeeder::class);
    grantAnnouncementPermissions('manager');

    $manager = announcementStaff('manager');
    $waiter = announcementStaff('waiter');
    announcementStaff('chef');

    $announcement = makeAnnouncement(['audience' => 'roles']);
    $announcement->syncTargetRoles(['waiter']);

    Livewire::actingAs($manager)
        ->test(ListAnnouncements::class)
        ->callTableAction('publish', $announcement, data: ['published_at' => null]);

    $announcement->refresh();

    expect($announcement->status())->toBe('published')
        ->and(AnnouncementRecipient::where('announcement_id', $announcement->id)->pluck('user_id')->all())
        ->toBe([$waiter->id]);
});

/**
 * A role-targeted notice with no roles chosen would publish to nobody and
 * still look like it worked. Surfaced as a notification rather than an
 * abort(), per the architecture test that forbids bare aborts.
 */
it('refuses to publish a role-targeted announcement with no roles chosen', function () {
    $this->seed(ShieldSeeder::class);
    grantAnnouncementPermissions('manager');

    $manager = announcementStaff('manager');
    announcementStaff('waiter');

    $announcement = makeAnnouncement(['audience' => 'roles']);

    Livewire::actingAs($manager)
        ->test(ListAnnouncements::class)
        ->callTableAction('publish', $announcement, data: ['published_at' => null])
        ->assertNotified();

    expect($announcement->fresh()->status())->toBe('draft')
        ->and(AnnouncementRecipient::where('announcement_id', $announcement->id)->count())->toBe(0);
});

it('withdraws a published announcement without touching the roster', function () {
    $this->seed(ShieldSeeder::class);
    grantAnnouncementPermissions('manager');

    $manager = announcementStaff('manager');
    announcementStaff('waiter');

    $announcement = makeAnnouncement();
    app(AnnouncementService::class)->publish($announcement);

    Livewire::actingAs($manager)
        ->test(ListAnnouncements::class)
        ->callTableAction('unpublish', $announcement);

    expect($announcement->fresh()->status())->toBe('unpublished')
        ->and(AnnouncementRecipient::where('announcement_id', $announcement->id)->count())->toBe(2);
});

/**
 * "I have read this" only means something if the text cannot be rewritten
 * under the people who already signed it — the same reasoning that makes
 * FolioLine immutable. The expiry stays editable because bringing a notice
 * down early changes nothing about what anyone agreed to.
 */
it('locks the wording and audience once published but leaves the expiry editable', function () {
    $this->seed(ShieldSeeder::class);
    grantAnnouncementPermissions('manager');

    $manager = announcementStaff('manager');
    announcementStaff('waiter');

    $draft = makeAnnouncement();

    Livewire::actingAs($manager)
        ->test(EditAnnouncement::class, ['record' => $draft->getRouteKey()])
        ->assertFormFieldIsEnabled('title')
        ->assertFormFieldIsEnabled('body');

    app(AnnouncementService::class)->publish($draft);

    Livewire::actingAs($manager)
        ->test(EditAnnouncement::class, ['record' => $draft->getRouteKey()])
        ->assertFormFieldIsDisabled('title')
        ->assertFormFieldIsDisabled('body')
        ->assertFormFieldIsDisabled('severity')
        ->assertFormFieldIsDisabled('audience')
        ->assertFormFieldIsDisabled('must_acknowledge')
        ->assertFormFieldIsEnabled('expires_at');
});

/**
 * The roster is the point of the whole feature, so the "who has not read
 * this" side of it needs to be right: signed people show their signature
 * and where it came from, unsigned people stay visible as outstanding.
 */
it('lists both the signed and the outstanding people on the read receipts', function () {
    $this->seed(ShieldSeeder::class);
    grantAnnouncementPermissions('manager');

    $manager = announcementStaff('manager');
    $signed = announcementStaff('waiter', 'Signed Sam');
    announcementStaff('waiter', 'Outstanding Ola');

    $announcement = makeAnnouncement(['audience' => 'roles']);
    $announcement->syncTargetRoles(['waiter']);

    $service = app(AnnouncementService::class);
    $service->publish($announcement);
    $service->acknowledge($announcement, $signed, 'kiosk');

    $component = Livewire::actingAs($manager)->test(
        \App\Filament\Resources\Announcements\RelationManagers\RecipientsRelationManager::class,
        ['ownerRecord' => $announcement, 'pageClass' => EditAnnouncement::class],
    );

    $component->assertSuccessful()
        ->assertSee('Signed Sam')
        ->assertSee('Outstanding Ola')
        ->assertSee('Kiosk / phone');

    expect($announcement->fresh()->outstandingCount())->toBe(1);
});

it('never lets the read receipts be edited or deleted from the panel', function () {
    $this->seed(ShieldSeeder::class);
    grantAnnouncementPermissions('manager');

    $manager = announcementStaff('manager');
    announcementStaff('waiter');

    $announcement = makeAnnouncement();
    app(AnnouncementService::class)->publish($announcement);

    $relationManager = Livewire::actingAs($manager)->test(
        \App\Filament\Resources\Announcements\RelationManagers\RecipientsRelationManager::class,
        ['ownerRecord' => $announcement, 'pageClass' => EditAnnouncement::class],
    );

    // A signature an admin screen can add, change or remove is not
    // evidence of anything.
    expect($relationManager->instance()->isReadOnly())->toBeTrue();
});
