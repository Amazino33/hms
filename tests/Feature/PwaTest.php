<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can access PWA manifest', function () {
    $response = $this->get('/site.webmanifest');

    $response->assertStatus(200)
             ->assertHeader('Content-Type', 'application/manifest+json');
});

it('can access service worker', function () {
    $response = $this->get('/sw.js');

    $response->assertStatus(200)
             ->assertHeader('Content-Type', 'application/javascript');
});

it('can access browser config', function () {
    $response = $this->get('/browserconfig.xml');

    $response->assertStatus(200)
             ->assertHeader('Content-Type', 'text/xml');
});

it('manifest contains valid JSON', function () {
    $response = $this->get('/site.webmanifest');

    $response->assertStatus(200);

    $manifest = json_decode($response->getContent(), true);
    expect($manifest)->toBeArray();
    expect($manifest)->toHaveKey('name');
    expect($manifest)->toHaveKey('short_name');
    expect($manifest)->toHaveKey('start_url');
    expect($manifest)->toHaveKey('display');
    expect($manifest)->toHaveKey('icons');
});