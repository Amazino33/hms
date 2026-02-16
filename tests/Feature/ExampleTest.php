<?php

test('returns a successful response', function () {
    $response = $this->get(route('home'));

    // Homepage redirects to the admin panel in this app
    $response->assertRedirect('/admin');
});