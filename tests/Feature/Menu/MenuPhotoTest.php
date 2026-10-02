<?php

use App\Filament\Resources\MenuItems\Pages\EditMenuItem;
use App\Filament\Resources\MenuItems\Pages\ListMenuItems;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Product;
use App\Models\User;
use App\Services\MenuPhotoProcessor;
use Database\Seeders\ShieldSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Phase 1A — menu photos: one upload becomes a 400px and a 1000px WebP,
 * the original is never kept, and a re-upload always gets new file names.
 */
beforeEach(function () {
    Storage::fake(MenuPhotoProcessor::DISK);
});

function mpAdmin(): User
{
    // Same as every resource test: Shield permissions come from the seeder.
    test()->seed(ShieldSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    return $admin;
}

function mpMenuItem(): MenuItem
{
    $food = Category::create(['name' => 'Food', 'type' => 'food']);

    return MenuItem::create(['name' => 'Jollof Rice', 'sku' => 'MI-MP-'.uniqid(), 'category_id' => $food->id, 'type' => 'food', 'sale_price' => 4500, 'available_for_sale' => true]);
}

function mpWidth(string $path): int
{
    return getimagesizefromstring(Storage::disk(MenuPhotoProcessor::DISK)->get($path))[0];
}

it('turns a 6 MB jpg into a 400px thumb and a 1000px large WebP, keeping no original', function () {
    $upload = UploadedFile::fake()->image('dish.jpg', 4000, 3000)->size(6 * 1024);

    $paths = (new MenuPhotoProcessor)->process($upload);

    $disk = Storage::disk(MenuPhotoProcessor::DISK);
    expect($disk->allFiles())->toEqualCanonicalizing([$paths['photo_path'], $paths['photo_thumb_path']]);
    expect($paths['photo_path'])->toEndWith('-large.webp');
    expect($paths['photo_thumb_path'])->toEndWith('-thumb.webp');

    expect(getimagesizefromstring($disk->get($paths['photo_path']))['mime'])->toBe('image/webp');
    expect(mpWidth($paths['photo_path']))->toBeLessThanOrEqual(1000)->toBeGreaterThan(400);
    expect(mpWidth($paths['photo_thumb_path']))->toBeLessThanOrEqual(400);

    // No EXIF/XMP survives (location, camera model).
    foreach ($paths as $path) {
        expect($disk->get($path))->not->toContain('Exif')->not->toContain('<x:xmpmeta');
    }
});

it('never upscales a small photo', function () {
    $paths = (new MenuPhotoProcessor)->process(UploadedFile::fake()->image('small.png', 300, 200));

    expect(mpWidth($paths['photo_path']))->toBe(300);
    expect(mpWidth($paths['photo_thumb_path']))->toBe(300);
});

it('gives a re-upload new file names and deletes the old files', function () {
    $item = mpMenuItem();
    $processor = new MenuPhotoProcessor;

    $item->update(['photo_path' => $processor->process(UploadedFile::fake()->image('a.jpg', 1200, 900))['photo_path']]);
    $first = [$item->photo_path, $item->photo_thumb_path];
    expect($item->photo_thumb_path)->toBe(MenuPhotoProcessor::thumbPathFor($item->photo_path));

    $item->update(['photo_path' => $processor->process(UploadedFile::fake()->image('b.jpg', 1200, 900))['photo_path']]);
    $second = [$item->fresh()->photo_path, $item->fresh()->photo_thumb_path];

    expect(array_intersect($first, $second))->toBe([]);
    Storage::disk(MenuPhotoProcessor::DISK)->assertMissing($first);
    Storage::disk(MenuPhotoProcessor::DISK)->assertExists($second);
    expect(Storage::disk(MenuPhotoProcessor::DISK)->allFiles())->toHaveCount(2);
});

it('deletes both files when the photo is removed', function () {
    $item = mpMenuItem();
    $item->update(['photo_path' => (new MenuPhotoProcessor)->process(UploadedFile::fake()->image('a.jpg', 800, 600))['photo_path']]);

    $item->update(['photo_path' => null]);

    expect($item->fresh()->photo_thumb_path)->toBeNull();
    expect(Storage::disk(MenuPhotoProcessor::DISK)->allFiles())->toBe([]);
});

it('rejects a photo over 8 MB, a non-image, and HEIC', function (UploadedFile $file, string $message) {
    expect(fn () => (new MenuPhotoProcessor)->process($file))->toThrow(Exception::class, $message);
    expect(Storage::disk(MenuPhotoProcessor::DISK)->allFiles())->toBe([]);
})->with([
    'over 8 MB' => fn () => [UploadedFile::fake()->image('huge.jpg', 1000, 800)->size(8 * 1024 + 1), 'over 8 MB'],
    'a pdf' => fn () => [UploadedFile::fake()->create('menu.pdf', 100, 'application/pdf'), 'JPG, PNG or WebP'],
    'heic' => fn () => [UploadedFile::fake()->create('IMG_0001.heic', 900, 'image/heic'), 'HEIC'],
]);

it('saves a photo and description from the admin menu-item form', function () {
    $item = mpMenuItem();

    Livewire::actingAs(mpAdmin())
        ->test(EditMenuItem::class, ['record' => $item->getRouteKey()])
        ->fillForm([
            'photo_path' => UploadedFile::fake()->image('jollof.jpg', 2000, 1500),
            'description' => 'Smoky party jollof with fried plantain.',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $item->refresh();
    expect($item->description)->toBe('Smoky party jollof with fried plantain.');
    expect($item->photo_path)->toEndWith('-large.webp');
    Storage::disk(MenuPhotoProcessor::DISK)->assertExists([$item->photo_path, $item->photo_thumb_path]);
    expect(Storage::disk(MenuPhotoProcessor::DISK)->allFiles())->toHaveCount(2);
});

it('rejects a wrong file type and an over-long description in the admin form', function () {
    $item = mpMenuItem();

    Livewire::actingAs(mpAdmin())
        ->test(EditMenuItem::class, ['record' => $item->getRouteKey()])
        ->fillForm([
            'photo_path' => UploadedFile::fake()->create('menu.pdf', 100, 'application/pdf'),
            'description' => str_repeat('x', 161),
        ])
        ->call('save')
        ->assertHasFormErrors(['photo_path', 'description']);

    expect($item->fresh()->photo_path)->toBeNull();
    expect(Storage::disk(MenuPhotoProcessor::DISK)->allFiles())->toBe([]);
});

it('gives products (the drinks on the guest menu) the same photo and description', function () {
    $drinks = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    // The product form's base-unit select only offers units that exist.
    \App\Models\Unit::firstOrCreate(['name' => 'bottle']);
    $malt = Product::create(['name' => 'Malta', 'price' => 1000, 'category_id' => $drinks->id, 'is_active' => true, 'base_unit' => 'bottle']);

    Livewire::actingAs(mpAdmin())
        ->test(EditProduct::class, ['record' => $malt->getRouteKey()])
        ->fillForm([
            'photo_path' => UploadedFile::fake()->image('malta.png', 900, 1200),
            'description' => 'Ice-cold malt drink.',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $malt->refresh();
    expect($malt->description)->toBe('Ice-cold malt drink.');
    Storage::disk(MenuPhotoProcessor::DISK)->assertExists([$malt->photo_path, $malt->photo_thumb_path]);
});

it('filters the menu-item list down to items still missing a photo', function () {
    $without = mpMenuItem();
    $with = MenuItem::create(['name' => 'Egusi', 'sku' => 'MI-MP-'.uniqid(), 'category_id' => $without->category_id, 'type' => 'food', 'sale_price' => 3000, 'available_for_sale' => true]);
    $with->update(['photo_path' => (new MenuPhotoProcessor)->process(UploadedFile::fake()->image('e.jpg', 800, 600))['photo_path']]);

    Livewire::actingAs(mpAdmin())
        ->test(ListMenuItems::class)
        ->filterTable('missing_photo')
        ->assertCanSeeTableRecords([$without])
        ->assertCanNotSeeTableRecords([$with]);
});
