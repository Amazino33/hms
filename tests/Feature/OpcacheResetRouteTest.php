<?php

it('resets opcache with the correct token', function () {
    $token = hash('sha256', config('app.key'));

    $this->get("/__ops/reset-opcache/{$token}")
        ->assertStatus(200);
});

it('404s on a wrong token, never running opcache_reset', function () {
    $this->get('/__ops/reset-opcache/not-the-real-token')
        ->assertStatus(404);
});
