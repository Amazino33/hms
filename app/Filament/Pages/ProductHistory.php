<?php

namespace App\Filament\Pages;

use App\Models\Product;
use App\Services\PermissionService;
use BackedEnum;
use Filament\Pages\Page;
use Livewire\Attributes\Computed;

/**
 * The forensic counterpart to soft-deleting a product: everything a
 * ProductDeletionRequest reviewer (or anyone auditing one afterward) needs
 * to see — where it's been stocked, what moved, whether it was ever
 * counted, and its adjustment/deletion-request history — in one read-only
 * place. Works for a live product or a soft-deleted one (withTrashed), on
 * purpose: this is exactly the record a cover-up would try to make
 * unreachable.
 */
class ProductHistory extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.product-history';

    protected static ?string $slug = 'product-history';

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    public ?int $productId = null;

    #[Computed]
    public function product(): ?Product
    {
        return Product::withTrashed()->find($this->productId);
    }

    public function mount(?int $product_id = null): void
    {
        // Same reason as CountSessionDetail::mount() — Filament page routing
        // doesn't forward ?product_id= into mount() on a real HTTP GET the
        // way a test's Livewire::test(['product_id' => ...]) does.
        $queryValue = request()->integer('product_id');
        $this->productId = $product_id ?? ($queryValue > 0 ? $queryValue : null);
    }

    #[Computed]
    public function inventoryByWarehouse()
    {
        return $this->product?->inventory()->with('warehouse')->get() ?? collect();
    }

    #[Computed]
    public function transactions()
    {
        return $this->product?->transactions()->with('user')->latest()->limit(100)->get() ?? collect();
    }

    #[Computed]
    public function countSessionItems()
    {
        return $this->product?->countSessionItems()->with('session.warehouse')->latest()->limit(100)->get() ?? collect();
    }

    #[Computed]
    public function stockAdjustments()
    {
        return $this->product?->stockAdjustments()->with(['requestedBy', 'reviewedBy', 'warehouse'])->latest()->get() ?? collect();
    }

    #[Computed]
    public function deletionRequests()
    {
        return $this->product?->deletionRequests()->with(['requestedBy', 'reviewedBy'])->latest()->get() ?? collect();
    }
}
