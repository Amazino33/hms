<?php

namespace App\Filament\Resources\Products\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only. Stock quantity can only move through recorded, attributable
 * events (purchases, transfers, sales, returns, and approved Stock
 * Adjustments) — never by editing a number here, for any role, including
 * super_admin. Use Quick Inventory Update (purchases/initial stock), Stock
 * Transfers, or the Stock Adjustment flow instead.
 */
class InventoryRelationManager extends RelationManager
{
    protected static string $relationship = 'inventory';

    public function form(Schema $schema): Schema
    {
        return $schema->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('warehouse.name')
                    ->label('Warehouse')
                    ->sortable(),
                TextColumn::make('quantity')
                    ->label('Current Stock')
                    ->numeric(),
            ])
            ->headerActions([])
            ->actions([]);
    }
}
