<?php

use Illuminate\Support\Facades\Route;

// Redirect the homepage directly to the Admin Panel
Route::get('/', function () {
    return redirect('/admin');
});

require __DIR__.'/settings.php';
