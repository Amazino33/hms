<?php

use App\Models\User;
use App\Services\PinAuthService;
use Database\Seeders\ShieldSeeder;
use Livewire\Livewire;

/**
 * Staff 0A §5. Choosing a PIN someone else holds no longer says so, and
 * repeated rejections lock PIN changes for an hour and tell the super admins.
 */
beforeEach(function () {
    $this->seed(ShieldSeeder::class);

    $this->owner = User::factory()->create(['name' => 'Owner']);
    $this->owner->assignRole('super_admin');

    $this->holder = User::factory()->create(['name' => 'Holder']);
    (new PinAuthService)->setPin($this->holder, '4826');

    $this->seeker = User::factory()->create(['name' => 'Seeker']);
});

it('gives a generic message for a PIN that is already in use', function () {
    expect(fn () => (new PinAuthService)->setPin($this->seeker, '4826'))
        ->toThrow(\InvalidArgumentException::class, "This PIN can't be used. Please choose a different one.");

    $this->actingAs($this->seeker);
    Livewire::test('pages::settings.pin')
        ->set('pin', '4826')
        ->set('pin_confirmation', '4826')
        ->call('updatePin')
        ->assertHasErrors(['pin'])
        ->assertSee("This PIN can't be used. Please choose a different one.")
        ->assertDontSee('someone else');

    // Uniqueness itself is unchanged: the kiosk resolves people by PIN.
    expect($this->seeker->fresh()->pin_hash)->toBeNull();
});

it('locks PIN changes after three rejections in an hour and notifies the super admins', function () {
    $service = new PinAuthService;

    foreach (['4826', '1111', '4826'] as $rejected) {
        expect(fn () => $service->setPin($this->seeker, $rejected))->toThrow(\InvalidArgumentException::class);
    }

    $notice = $this->owner->notifications()->sole();
    expect($notice->data['title'])->toBe('Repeated PIN rejections for Seeker');

    // The fourth attempt is locked — even a perfectly good PIN.
    expect(fn () => $service->setPin($this->seeker, '5039'))
        ->toThrow(\InvalidArgumentException::class, 'Too many attempts');
    expect($this->seeker->fresh()->pin_hash)->toBeNull();
    expect($this->owner->notifications()->count())->toBe(1);

    // Another person is unaffected.
    $other = User::factory()->create();
    $service->setPin($other, '5039');
    expect($other->fresh()->pin_hash)->not->toBeNull();

    // An hour later the lock has lifted.
    $this->travel(61)->minutes();
    $service->setPin($this->seeker, '6172');
    expect($this->seeker->fresh()->pin_hash)->not->toBeNull();
});
