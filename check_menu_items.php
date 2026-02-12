<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $menuItems = DB::table('menu_items')->get();
    echo "Menu items in database:\n";
    foreach ($menuItems as $item) {
        echo "- ID: {$item->id}, Name: {$item->name}, Sale Price: {$item->sale_price}\n";
    }

    // Check specifically for Egusi Soup
    $egusi = DB::table('menu_items')->where('name', 'Egusi Soup')->first();
    if ($egusi) {
        echo "\n✅ Egusi Soup found: ID = {$egusi->id}\n";
    } else {
        echo "\n❌ Egusi Soup not found in database\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}