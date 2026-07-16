<?php

namespace App\Filament\Resources\Ingredients\Pages;

use App\Filament\Resources\Ingredients\IngredientResource;
use App\Models\IngredientInventoryItem;
use App\Models\IngredientTransaction;
use App\Models\WareHouse;
use App\Services\UserFeedback;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateIngredient extends CreateRecord
{
    protected static string $resource = IngredientResource::class;

    /**
     * The opening-stock figure entered on the create form is not just a raw
     * column write — it is the ingredient's first stock movement, so it must
     * go through the same transaction-logged path as every other stock
     * change (recorded at Main Store, since that is where storekeeper stock
     * conceptually lives before being transferred out to the kitchen).
     */
    protected function handleRecordCreation(array $data): Model
    {
        $openingStock = (float) ($data['quantity'] ?? 0);

        try {
            return DB::transaction(function () use ($data, $openingStock) {
                $ingredient = static::getModel()::create($data);

                if ($openingStock > 0) {
                    $mainStoreId = WareHouse::where('type', 'storage')->orderBy('id')->value('id') ?? 1;

                    IngredientInventoryItem::create([
                        'ingredient_id' => $ingredient->id,
                        'warehouse_id' => $mainStoreId,
                        'quantity' => $openingStock,
                    ]);

                    IngredientTransaction::create([
                        'ingredient_id' => $ingredient->id,
                        'warehouse_id' => $mainStoreId,
                        'type' => 'purchase',
                        'quantity' => $openingStock,
                        'cost_per_unit' => $data['cost_per_unit'] ?? null,
                        'reference' => 'opening_stock',
                        'user_id' => auth()->id(),
                    ]);
                }

                return $ingredient;
            });
        } catch (\Throwable $e) {
            report($e);
            UserFeedback::blocked('Could not create ingredient', 'A duplicate SKU or other data conflict blocked this. Check the details and try again.');

            throw new Halt();
        }
    }
}
