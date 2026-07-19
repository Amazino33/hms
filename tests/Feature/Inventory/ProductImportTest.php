<?php

use App\Imports\ProductImport;
use App\Models\Category;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\User;
use App\Models\WareHouse;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

function writeProductImportFixture(string $sku, int $quantity, string $warehouseName): string
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(['name', 'sku', 'category_id', 'price', 'cost', 'warehouse', 'quantity'], null, 'A1');
    $sheet->fromArray(['Imported Beer', $sku, 'Drinks', 500, 300, $warehouseName, $quantity], null, 'A2');

    $path = sys_get_temp_dir() . '/product_import_test_' . uniqid() . '.xlsx';
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);

    return $path;
}

it('adds to existing stock on re-import instead of silently overwriting it, and logs a transaction', function () {
    $admin = User::factory()->create();
    $this->actingAs($admin);

    Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $mainStore = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);

    // First import: brand-new product, 10 units.
    $path1 = writeProductImportFixture('SKU-IMPORT-1', 10, 'Main Store');
    Excel::import(new ProductImport(), $path1);
    unlink($path1);

    $product = Product::where('sku', 'SKU-IMPORT-1')->firstOrFail();
    expect((int) $product->inventory()->where('warehouse_id', $mainStore->id)->value('quantity'))->toBe(10);
    expect(InventoryTransaction::where('product_id', $product->id)->count())->toBe(1);

    // Second import of the SAME sku/warehouse: previously this OVERWROTE
    // quantity to 6 (data loss); it must now ADD to the existing 10.
    $path2 = writeProductImportFixture('SKU-IMPORT-1', 6, 'Main Store');
    Excel::import(new ProductImport(), $path2);
    unlink($path2);

    expect((int) $product->inventory()->where('warehouse_id', $mainStore->id)->value('quantity'))->toBe(16);
    expect(InventoryTransaction::where('product_id', $product->id)->count())->toBe(2);
});

it('always lands stock at Main Store, ignoring whatever warehouse the spreadsheet names', function () {
    $admin = User::factory()->create();
    $this->actingAs($admin);

    Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $mainStore = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $bar = WareHouse::create(['name' => 'Main Bar', 'type' => 'consumer']);

    // Sheet names the Bar warehouse — a product's first-ever stock record
    // must never land anywhere but Main Store, regardless.
    $path = writeProductImportFixture('SKU-IMPORT-2', 10, 'Main Bar');
    Excel::import(new ProductImport(), $path);
    unlink($path);

    $product = Product::where('sku', 'SKU-IMPORT-2')->firstOrFail();
    expect((int) $product->inventory()->where('warehouse_id', $mainStore->id)->value('quantity'))->toBe(10);
    expect($product->inventory()->where('warehouse_id', $bar->id)->exists())->toBeFalse();
});
