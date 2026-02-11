<?php

namespace App\Filament\Resources\Products\RelationManagers;

use Filament\Actions\AssociateAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DissociateAction;
use Filament\Actions\DissociateBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Table;

class InventoryRelationManager extends RelationManager
{
    protected static string $relationship = 'inventory';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('warehouse_id')
                    ->relationship('warehouse', 'name')
                    ->required()
                    // Disable selecting a warehouse that already has this product, except for storage warehouses
                    ->disableOptionWhen(function ($value, $record) {
                        if ($record !== null) return false; // Allow editing existing records
                        
                        $warehouse = \App\Models\Warehouse::find($value);
                        if (!$warehouse) return true;
                        
                        // Allow storage warehouses always, disable consumer warehouses that already have this product
                        if ($warehouse->type === 'storage') return false;
                        
                        return $this->ownerRecord->inventory()->where('warehouse_id', $value)->exists();
                    })
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set) {
                        // Reset quantity when warehouse changes
                        $warehouse = \App\Models\Warehouse::find($state);
                        if ($warehouse && $warehouse->type !== 'storage') {
                            $set('quantity', 0);
                        }
                    }),

                TextInput::make('quantity')
                    ->numeric()
                    ->default(0)
                    ->required()
                    ->disabled(function (callable $get) {
                        $warehouseId = $get('warehouse_id');
                        if (!$warehouseId) return true;
                        
                        $warehouse = \App\Models\Warehouse::find($warehouseId);
                        return !$warehouse || $warehouse->type !== 'storage';
                    })
                    ->helperText(function (callable $get) {
                        $warehouseId = $get('warehouse_id');
                        if (!$warehouseId) return '';
                        
                        $warehouse = \App\Models\Warehouse::find($warehouseId);
                        if (!$warehouse) return '';
                        
                        return $warehouse->type === 'storage' 
                            ? 'Enter quantity for storage warehouse' 
                            : 'Quantity managed through transfers from storage warehouses';
                    }),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id') // We don't really use this
            ->columns([
                TextColumn::make('warehouse.name')
                    ->label('Warehouse')
                    ->sortable(),
                TextInputColumn::make('quantity') // Editable directly in the table!
                    ->rules(['numeric', 'min:0'])
                    ->disabled(function ($record) {
                        return $record->warehouse->type !== 'storage';
                    })
                    ->placeholder(function ($record) {
                        return $record->warehouse->type !== 'storage' ? 'Managed via transfers' : 'Enter quantity';
                    }),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add to Warehouse'),
            ])
            ->actions([
                DeleteAction::make()
                    ->hidden(fn ($record) => $record->warehouse->type === 'storage'), // Can't delete storage warehouse inventory
            ]);
    }
}
