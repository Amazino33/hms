<?php

use App\Filament\Resources\StaffApplications\Pages\ListStaffApplications;
use App\Filament\Resources\StaffApplications\Pages\ViewStaffApplication;
use App\Models\StaffApplication;
use App\Models\StaffGuarantor;
use App\Models\User;
use App\Services\Attendance\AttendanceOnlyRoleService;
use App\Services\PinAuthService;
use App\Services\StaffApplicationService;
use App\Services\StaffApplicationSettings;
use Database\Seeders\ShieldSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * Staff 0C — deciding applications: approval creates the account (with a
 * permanent staff ID, the applicant's own password, their role and their
 * guarantor) in one go; rejection clears everything personal. Super admin
 * only, checked in the service.
 */
beforeEach(function () {
    $this->seed(ShieldSeeder::class);
    AttendanceOnlyRoleService::role();
    Storage::fake('local');

    $this->owner = User::factory()->create(['name' => 'Owner']);
    $this->owner->assignRole('super_admin');

    $this->admin = User::factory()->create(['name' => 'Office Admin']);
    $this->admin->assignRole('admin');

    StaffApplicationSettings::set(true, $this->owner);
});

function submitApplication(string $email = 'applicant@example.com', string $name = 'Ifeoma Nwosu'): StaffApplication
{
    return app(StaffApplicationService::class)->submit([
        'full_name' => $name,
        'phone' => '0803 123 4567',
        'email' => $email,
        'address' => '12 Aba Road',
        'password' => 'applicant-chose-this',
        'next_of_kin_name' => 'Kin',
        'next_of_kin_phone' => '0802 000 1111',
        'next_of_kin_relationship' => 'Sister',
        'bank_name' => 'zenith',
        'account_number' => '2233445566',
        'account_name' => $name,
        'guarantor_name' => 'Guarantor Person',
        'guarantor_phone' => '0805 222 3333',
        'guarantor_address' => '4 Garden City Way',
        'guarantor_relationship' => 'Pastor',
        'guarantor_occupation' => 'Clergy',
        'id_type' => 'voters',
        'id_number' => 'VIN-998877',
    ], [
        'photo' => UploadedFile::fake()->image('me.jpg'),
        'id_document' => UploadedFile::fake()->image('id.jpg'),
        'guarantor_form' => UploadedFile::fake()->image('form.jpg'),
    ]);
}

it('creates the staff account on approval: staff ID, own password, role, details, guarantor — and no PIN', function () {
    $application = submitApplication();

    Livewire::actingAs($this->owner)
        ->test(ViewStaffApplication::class, ['record' => $application->getRouteKey()])
        ->callAction('approve', data: ['staff_type' => 'app', 'roles' => ['waiter'], 'job_title' => 'Waiter'])
        ->assertHasNoActionErrors();

    $user = User::where('email', 'applicant@example.com')->sole();

    expect($user->staff_code)->toBe('SLM-0001');
    expect($user->name)->toBe('Ifeoma Nwosu');
    expect(Hash::check('applicant-chose-this', $user->password))->toBeTrue();
    expect($user->roles->pluck('name')->all())->toBe(['waiter']);
    expect($user->pin_hash)->toBeNull();
    expect($user->account_number)->toBe('2233445566');
    expect($user->id_type)->toBe('voters');
    expect($user->next_of_kin_relationship)->toBe('Sister');
    expect($user->phone)->toBe('0803 123 4567');
    expect($user->id_card_copy)->toBe($application->id_document_path);
    Storage::disk('local')->assertExists($user->photo_path);

    $guarantor = StaffGuarantor::where('user_id', $user->id)->sole();
    expect($guarantor->name)->toBe('Guarantor Person');
    expect($guarantor->form_path)->toBe($application->guarantor_form_path);

    $application->refresh();
    expect($application->status)->toBe(StaffApplication::STATUS_APPROVED);
    expect($application->user_id)->toBe($user->id);
    expect($application->reviewed_by)->toBe($this->owner->id);
    // No second copy of the password hash or the numbers on the application.
    expect($application->password_hash)->toBeNull();
    expect($application->account_number)->toBeNull();
});

it('issues staff IDs in order and never reuses one', function () {
    $service = app(StaffApplicationService::class);

    $first = $service->approve(submitApplication('a@example.com', 'First Person'), $this->owner, 'app', ['waiter']);
    $second = $service->approve(submitApplication('b@example.com', 'Second Person'), $this->owner, 'attendance_only');

    expect($first->staff_code)->toBe('SLM-0001');
    expect($second->staff_code)->toBe('SLM-0002');

    // A free-text code typed before this existed does not disturb the sequence.
    User::factory()->create(['staff_code' => 'LH-001']);
    $third = $service->approve(submitApplication('c@example.com', 'Third Person'), $this->owner, 'app', ['chef']);
    expect($third->staff_code)->toBe('SLM-0003');
});

it('makes an issued staff ID permanent', function () {
    $user = app(StaffApplicationService::class)->approve(submitApplication(), $this->owner, 'app', ['waiter']);

    expect(fn () => $user->update(['staff_code' => 'SLM-0999']))->toThrow(\LogicException::class);
    expect($user->fresh()->staff_code)->toBe('SLM-0001');

    $this->actingAs($this->owner)
        ->get("/admin/users/{$user->id}/edit")
        ->assertOk()
        ->assertSee('Permanent — issued at approval.');
});

it('makes an attendance-only person with no login at all', function () {
    $user = app(StaffApplicationService::class)->approve(submitApplication(), $this->owner, 'attendance_only');

    expect($user->password)->toBeNull();
    expect($user->roles->pluck('name')->all())->toBe([User::ATTENDANCE_ONLY_ROLE]);
    expect($user->staff_code)->toBe('SLM-0001');
});

it('lets the approved person sign in with their own password and set their own PIN', function () {
    $user = app(StaffApplicationService::class)->approve(submitApplication(), $this->owner, 'app', ['waiter']);

    $this->post('/login', ['email' => 'applicant@example.com', 'password' => 'applicant-chose-this'])->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($user);

    (new PinAuthService)->setPin($user, '5813');
    expect((new PinAuthService)->attempt('5813', 'kiosk-x')?->id)->toBe($user->id);
});

it('refuses deciding an application twice, or an email taken in the meantime', function () {
    $service = app(StaffApplicationService::class);
    $application = submitApplication();
    $service->approve($application, $this->owner, 'app', ['waiter']);

    expect(fn () => $service->approve($application, $this->owner, 'app', ['waiter']))->toThrow(ValidationException::class);
    expect(fn () => $service->reject($application, $this->owner, 'late'))->toThrow(ValidationException::class);

    $other = submitApplication('later@example.com', 'Later Person');
    User::factory()->create(['email' => 'later@example.com']);
    expect(fn () => $service->approve($other, $this->owner, 'app', ['waiter']))->toThrow(ValidationException::class);
    expect($other->fresh()->isPending())->toBeTrue();
});

it('rejects with a reason and clears every personal detail and file', function () {
    $application = submitApplication();
    $files = [$application->photo_path, $application->id_document_path, $application->guarantor_form_path];

    Livewire::actingAs($this->owner)
        ->test(ViewStaffApplication::class, ['record' => $application->getRouteKey()])
        ->callAction('reject', data: ['reason' => 'Position filled'])
        ->assertHasNoActionErrors();

    $application->refresh();
    expect($application->status)->toBe(StaffApplication::STATUS_REJECTED);
    expect($application->full_name)->toBe('Ifeoma Nwosu');
    expect($application->rejection_reason)->toBe('Position filled');

    foreach (StaffApplication::PERSONAL_FIELDS as $field) {
        expect($application->{$field})->toBeNull();
    }

    foreach ($files as $path) {
        Storage::disk('local')->assertMissing($path);
    }

    expect(User::where('email', 'applicant@example.com')->exists())->toBeFalse();
});

it('keeps everyone but the super admin out, on screen and in the service', function () {
    $application = submitApplication();

    $this->actingAs($this->admin)->get('/admin/staff-applications')->assertForbidden();

    expect(fn () => app(StaffApplicationService::class)->approve($application, $this->admin, 'attendance_only'))
        ->toThrow(ValidationException::class);
    expect(fn () => app(StaffApplicationService::class)->reject($application, $this->admin, 'no'))
        ->toThrow(ValidationException::class);

    expect($application->fresh()->isPending())->toBeTrue();
    expect(User::where('email', 'applicant@example.com')->exists())->toBeFalse();
});

it('shows pending applications to the super admin, with the switch and the link', function () {
    submitApplication();

    expect(\App\Filament\Resources\StaffApplications\StaffApplicationResource::getNavigationBadge())->toBe('1');

    Livewire::actingAs($this->owner)
        ->test(ListStaffApplications::class)
        ->assertCanSeeTableRecords(StaffApplication::all())
        ->assertSee(route('staff.join'))
        ->assertActionVisible('closeApplications')
        ->callAction('closeApplications');

    expect(StaffApplicationSettings::isOpen())->toBeFalse();
});
