<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

try {
    $columns = Schema::getColumnListing('order_items');
    echo "Columns in order_items table:\n";
    foreach ($columns as $column) {
        echo "- $column\n";
    }

    if (in_array('item_type', $columns)) {
        echo "\n✅ item_type column exists!\n";
    } else {
        echo "\n❌ item_type column is missing!\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}