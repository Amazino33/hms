<?php

namespace App\Filament\Ceo\Resources\Procurements\Schemas;

use App\Models\Procurement;
use App\Models\ProcurementIngredientItem;
use App\Models\ProcurementItem;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProcurementInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Procurement Details')
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('reference'),
                            TextEntry::make('location.name')->label('Location'),
                            TextEntry::make('supplier_name')->label('Supplier')->default('—'),
                            TextEntry::make('purchased_at')->date(),
                            TextEntry::make('total_cost')->money('NGN'),
                            TextEntry::make('recordedBy.name')->label('Recorded By'),
                        ]),
                    ]),

                Section::make('Products')
                    ->visible(fn (Procurement $record) => $record->items->isNotEmpty())
                    ->schema([
                        RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->schema([
                                TextEntry::make('summary')
                                    ->hiddenLabel()
                                    ->state(fn (ProcurementItem $record) => self::lineSummary(
                                        $record->product->name ?? 'Deleted product',
                                        (float) $record->entered_qty,
                                        $record->entered_unit === 'purchase_unit'
                                            ? ($record->product->purchase_unit_name ?? 'unit')
                                            : ($record->product->base_unit ?? 'unit'),
                                        $record->entered_unit === 'purchase_unit' ? (float) $record->base_qty : null,
                                        $record->product->base_unit ?? 'unit',
                                        (float) $record->unit_cost,
                                        (float) $record->line_total_cost,
                                    )),
                            ]),
                    ]),

                Section::make('Ingredients')
                    ->visible(fn (Procurement $record) => $record->ingredientItems->isNotEmpty())
                    ->schema([
                        RepeatableEntry::make('ingredientItems')
                            ->hiddenLabel()
                            ->schema([
                                TextEntry::make('summary')
                                    ->hiddenLabel()
                                    ->state(fn (ProcurementIngredientItem $record) => self::lineSummary(
                                        $record->ingredient->name ?? 'Deleted ingredient',
                                        (float) $record->entered_qty,
                                        $record->entered_unit === 'purchase_unit'
                                            ? ($record->ingredient->purchase_unit_name ?? 'unit')
                                            : ($record->ingredient->unit_name ?? 'unit'),
                                        $record->entered_unit === 'purchase_unit' ? (float) $record->base_qty : null,
                                        $record->ingredient->unit_name ?? 'unit',
                                        (float) $record->unit_cost,
                                        (float) $record->line_total_cost,
                                    )),
                            ]),
                    ]),
            ]);
    }

    /**
     * One line of text per procurement item, matching the exact format
     * already used in the storekeeper's own "Recent Procurements" panel —
     * name, entered qty in the unit it was entered, its base-unit
     * conversion when it was entered as a purchase pack, unit cost, and
     * the line total.
     */
    private static function lineSummary(
        string $name,
        float $enteredQty,
        string $enteredUnitLabel,
        ?float $baseQty,
        string $baseUnitLabel,
        float $unitCost,
        float $lineTotal,
    ): string {
        $qty = rtrim(rtrim(number_format($enteredQty, 2), '0'), '.');
        $summary = "{$name} — {$qty} {$enteredUnitLabel}(s)";

        if ($baseQty !== null) {
            $base = rtrim(rtrim(number_format($baseQty, 2), '0'), '.');
            $plural = $baseQty == 1 ? '' : 's';
            $summary .= " ({$base} {$baseUnitLabel}{$plural})";
        }

        $summary .= ' · ₦' . number_format($unitCost, 2) . "/{$baseUnitLabel}";
        $summary .= ' · ₦' . number_format($lineTotal, 2) . ' total';

        return $summary;
    }
}
