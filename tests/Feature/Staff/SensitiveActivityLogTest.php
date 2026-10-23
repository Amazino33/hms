<?php

use App\Models\User;
use App\Services\PinAuthService;
use Spatie\Activitylog\Models\Activity;

/**
 * Staff 0A §6. Sensitive staff details are logged as "changed, by whom" —
 * never their values.
 */
function activityLogDump(): string
{
    return json_encode(Activity::all()->map(fn (Activity $a) => $a->getAttributes())->all());
}

it('records that bank and ID details changed, and who changed them, without the values', function () {
    $admin = User::factory()->create(['name' => 'Office Admin']);
    $staff = User::factory()->create();

    $this->actingAs($admin);

    $staff->update([
        'bank_name' => 'gtbank',
        'account_number' => '0123456789',
        'account_name' => 'Ada Obi Savings',
        'id_type' => 'nin',
        'id_number' => '98765432101',
        'next_of_kin_phone' => '08031234567',
        'job_title' => 'Head Waiter',
    ]);

    $dump = activityLogDump();
    foreach (['0123456789', 'Ada Obi Savings', '98765432101', '08031234567', 'gtbank'] as $value) {
        expect($dump)->not->toContain($value);
    }

    $entry = Activity::where('description', 'Sensitive details updated: bank, ID, next of kin')->sole();
    expect($entry->causer_id)->toBe($admin->id);
    expect($entry->subject_id)->toBe($staff->id);

    // Ordinary fields are still logged with their values, as before.
    expect($dump)->toContain('Head Waiter');
});

it('records a PIN change without the hash', function () {
    $staff = User::factory()->create();
    $this->actingAs($staff);

    (new PinAuthService)->setPin($staff, '7391');

    expect(Activity::where('description', 'Sensitive details updated: PIN')->where('subject_id', $staff->id)->exists())->toBeTrue();
    expect(activityLogDump())->not->toContain($staff->fresh()->pin_hash);
});

it('records sensitive details given at creation without the values', function () {
    User::factory()->create(['account_number' => '1112223334', 'guarantor_form' => 'guarantors/form.pdf']);

    expect(Activity::where('description', 'Sensitive details set: bank, documents')->exists())->toBeTrue();
    expect(activityLogDump())->not->toContain('1112223334')->not->toContain('guarantors/form.pdf');
});
