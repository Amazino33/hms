<?php

use App\Models\StaffApplication;
use App\Models\User;
use App\Services\StaffApplicationSettings;
use Database\Seeders\ShieldSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * Staff 0C — the public /join form.
 */
beforeEach(function () {
    $this->seed(ShieldSeeder::class);
    Storage::fake('local');
    RateLimiter::clear('staff-apply-ip:127.0.0.1');

    $this->owner = User::factory()->create(['name' => 'Owner']);
    $this->owner->assignRole('super_admin');
});

function openApplications(User $owner): void
{
    StaffApplicationSettings::set(true, $owner);
}

/** @return array<string, mixed> */
function validJoinData(array $overrides = []): array
{
    return $overrides + [
        'full_name' => 'Chidinma Okafor',
        'phone' => '0803 123 4567',
        'email' => 'chidinma@example.com',
        'address' => '12 Aba Road, Port Harcourt',
        'password' => 'my-own-secret',
        'password_confirmation' => 'my-own-secret',
        'next_of_kin_name' => 'Ngozi Okafor',
        'next_of_kin_phone' => '0802 000 1111',
        'next_of_kin_relationship' => 'Mother',
        'bank_name' => 'gtbank',
        'account_number' => '0123456789',
        'account_name' => 'Chidinma Okafor',
        'guarantor_name' => 'Emeka Obi',
        'guarantor_phone' => '0805 222 3333',
        'guarantor_address' => '4 Garden City Way',
        'guarantor_relationship' => 'Uncle',
        'guarantor_occupation' => 'Teacher',
        'id_type' => 'nin',
        'id_number' => '12345678901',
    ];
}

function fillJoinForm(array $overrides = []): \Livewire\Features\SupportTesting\Testable
{
    $component = Livewire::test('pages::join');

    foreach (validJoinData($overrides) as $field => $value) {
        $component->set($field, $value);
    }

    return $component
        ->set('photo', UploadedFile::fake()->image('me.jpg', 800, 800))
        ->set('id_document', UploadedFile::fake()->image('nin.jpg', 1200, 800))
        ->set('guarantor_form', UploadedFile::fake()->image('form.jpg', 1200, 1600));
}

it('is reachable without logging in, and shows a closed message while applications are off', function () {
    $this->get('/join')->assertOk()->assertSee('Applications are currently closed');
    $this->assertGuest();

    fillJoinForm()->call('submit')->assertHasErrors(['form']);
    expect(StaffApplication::count())->toBe(0);
});

it('takes a complete application as Pending, with no user account and no login', function () {
    openApplications($this->owner);
    $usersBefore = User::count();

    fillJoinForm()->call('submit')->assertHasNoErrors()->assertSet('submittedReference', fn ($ref) => str_starts_with((string) $ref, 'APP-'));

    $application = StaffApplication::sole();
    expect($application->status)->toBe(StaffApplication::STATUS_PENDING);
    expect($application->full_name)->toBe('Chidinma Okafor');
    expect(User::count())->toBe($usersBefore);
    expect(User::where('email', 'chidinma@example.com')->exists())->toBeFalse();

    // Only a hash of the password is kept.
    expect($application->password_hash)->not->toBe('my-own-secret');
    expect(Hash::check('my-own-secret', $application->password_hash))->toBeTrue();
});

it('stores photos and documents on the private disk, re-encoded', function () {
    openApplications($this->owner);

    fillJoinForm()->call('submit')->assertHasNoErrors();

    $application = StaffApplication::sole();

    foreach (['photo_path', 'id_document_path', 'guarantor_form_path'] as $attribute) {
        expect($application->{$attribute})->toStartWith('staff-applications/'.$application->reference.'/');
        expect($application->{$attribute})->toEndWith('.webp');
        Storage::disk('local')->assertExists($application->{$attribute});
    }

    expect(file_exists(public_path($application->photo_path)))->toBeFalse();
});

it('encrypts the account and ID numbers at rest', function () {
    openApplications($this->owner);

    fillJoinForm()->call('submit')->assertHasNoErrors();

    $raw = DB::table('staff_applications')->first();
    expect($raw->account_number)->not->toContain('0123456789');
    expect($raw->id_number)->not->toContain('12345678901');

    expect(StaffApplication::sole()->account_number)->toBe('0123456789');
});

it('refuses an email that already belongs to staff or to a pending application', function () {
    openApplications($this->owner);
    User::factory()->create(['email' => 'taken@example.com']);

    fillJoinForm(['email' => 'taken@example.com'])->call('submit')->assertHasErrors(['email']);

    fillJoinForm()->call('submit')->assertHasNoErrors();
    fillJoinForm(['full_name' => 'Someone Else'])->call('submit')->assertHasErrors(['email']);

    expect(StaffApplication::count())->toBe(1);
});

it('validates the form', function () {
    openApplications($this->owner);

    Livewire::test('pages::join')
        ->call('submit')
        ->assertHasErrors(['full_name', 'phone', 'email', 'address', 'password', 'bank_name', 'account_number', 'guarantor_name', 'id_type', 'photo', 'id_document', 'guarantor_form']);

    fillJoinForm(['account_number' => '12345', 'password_confirmation' => 'different'])
        ->call('submit')
        ->assertHasErrors(['account_number', 'password']);

    expect(StaffApplication::count())->toBe(0);
});

it('quietly drops a submission that filled the hidden trap field', function () {
    openApplications($this->owner);

    fillJoinForm()->set('website', 'http://spam.example')->call('submit')
        ->assertSet('submittedReference', fn ($ref) => $ref !== null);

    expect(StaffApplication::count())->toBe(0);
});

it('limits submissions per network address', function () {
    openApplications($this->owner);

    foreach (range(1, 10) as $i) {
        fillJoinForm(['email' => "applicant{$i}@example.com"])->call('submit')->assertHasNoErrors();
    }

    fillJoinForm(['email' => 'one-too-many@example.com'])->call('submit')->assertHasErrors(['form']);
    expect(StaffApplication::count())->toBe(10);
});

it('tells the super admins and logs the submission without personal values', function () {
    openApplications($this->owner);

    fillJoinForm()->call('submit')->assertHasNoErrors();

    expect($this->owner->notifications()->sole()->data['title'])->toBe('New staff application');

    $log = json_encode(Activity::where('log_name', 'staff_application')->get()->map->getAttributes());
    foreach (['0123456789', '12345678901', '0803 123 4567', 'Aba Road', 'chidinma@example.com'] as $value) {
        expect($log)->not->toContain($value);
    }
});

it('accepts a PDF for the ID and guarantor form', function () {
    openApplications($this->owner);

    $application = app(\App\Services\StaffApplicationService::class)->submit(validJoinData(), [
        'photo' => UploadedFile::fake()->image('me.jpg'),
        'id_document' => UploadedFile::fake()->create('nin.pdf', 200, 'application/pdf'),
        'guarantor_form' => UploadedFile::fake()->create('form.pdf', 200, 'application/pdf'),
    ]);

    expect($application->id_document_path)->toEndWith('.pdf');
    Storage::disk('local')->assertExists($application->guarantor_form_path);
});

it('keeps a browser draft of the typed answers, but never the password or the hidden trap field', function () {
    openApplications($this->owner);

    $component = Livewire::test('pages::join');
    $draftFields = $component->instance()::DRAFT_FIELDS;

    expect($draftFields)->not->toContain('password', 'password_confirmation', 'website', 'photo', 'id_document', 'guarantor_form');

    foreach ($draftFields as $field) {
        expect($component->get($field))->toBeString();
    }

    $component->assertSeeHtml('hms-join-draft');
});
