<?php

use App\Models\Product;
use App\Models\Category;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

it('logs a product price change with old and new values', function () {
    $admin = User::factory()->create();
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);

    $this->actingAs($admin);
    $product->update(['price' => 600]);

    $activity = Activity::where('log_name', 'product')->where('subject_id', $product->id)->where('event', 'updated')->latest('id')->first();

    expect($activity)->not->toBeNull();
    expect($activity->causer_id)->toBe($admin->id);
    expect($activity->attribute_changes['attributes']['price'])->toEqual(600);
    expect($activity->attribute_changes['old']['price'])->toEqual(500);
});

it('does not log a product creation twice or log unrelated attributes', function () {
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);

    $count = Activity::where('log_name', 'product')->where('subject_id', $product->id)->count();
    expect($count)->toBe(1);
});

it('logs a successful login with the causer set', function () {
    $user = User::factory()->create();

    auth()->login($user);
    event(new \Illuminate\Auth\Events\Login('web', $user, false));

    $activity = Activity::where('log_name', 'auth')->latest('id')->first();
    expect($activity)->not->toBeNull();
    expect($activity->causer_id)->toBe($user->id);
    expect($activity->description)->toContain('Login');
});

it('logs a failed login attempt without leaking the password', function () {
    event(new \Illuminate\Auth\Events\Failed('web', null, ['email' => 'someone@example.com', 'password' => 'supersecret']));

    $activity = Activity::where('log_name', 'auth')->latest('id')->first();
    expect($activity)->not->toBeNull();
    expect($activity->properties['email'])->toBe('someone@example.com');
    expect(json_encode($activity->properties))->not->toContain('supersecret');
});

it('logs a role being attached to a user', function () {
    $admin = User::factory()->create();
    $waiter = User::factory()->create();
    Role::firstOrCreate(['name' => 'waiter']);

    $this->actingAs($admin);
    $waiter->assignRole('waiter');

    $activity = Activity::where('log_name', 'role')->latest('id')->first();
    expect($activity)->not->toBeNull();
    expect($activity->subject_id)->toBe($waiter->id);
});

it('never logs the raw password value on a user update', function () {
    $admin = User::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($admin);
    $user->update(['password' => bcrypt('newpassword123'), 'name' => 'Renamed']);

    $activity = Activity::where('log_name', 'user')->where('subject_id', $user->id)->latest('id')->first();
    expect($activity)->not->toBeNull();
    expect($activity->properties->toArray())->not->toHaveKey('password');
    expect(json_encode($activity->properties))->not->toContain('newpassword123');
});
