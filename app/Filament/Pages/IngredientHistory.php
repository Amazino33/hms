<?php

namespace App\Filament\Pages;

use App\Models\Ingredient;
use App\Services\PermissionService;
use BackedEnum;
use Filament\Pages\Page;
use Livewire\Attributes\Computed;

class IngredientHistory extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.ingredient-history';

    protected static ?string $slug = 'ingredient-history';

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    public ?int $ingredientId = null;

    #[Computed]
    public function ingredient(): ?Ingredient
    {
        return Ingredient::find($this->ingredientId);
    }

    public function mount(?int $ingredient_id = null): void
    {
        $queryValue = request()->integer('ingredient_id');
        $this->ingredientId = $ingredient_id ?? ($queryValue > 0 ? $queryValue : null);
    }

    #[Computed]
    public function inventoryByWarehouse()
    {
        return $this->ingredient?->inventory()->with('warehouse')->get() ?? collect();
    }

    #[Computed]
    public function transactions()
    {
        return $this->ingredient?->transactions()->with('user')->latest()->limit(100)->get() ?? collect();
    }
}
