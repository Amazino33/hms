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
                    // Disable selecting a warehouse that already has this product
                    ->disableOptionWhen(fn ($value, $record, $livewire) => 
                        $record === null // Only disable on create, not edit
                        && $livewire->ownerRecord->inventory()->where('warehouse_id', $value)->exists()
                    ),

                TextInput::make('quantity')
                    ->numeric()
                    ->default(0)
                    ->required(),
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
                    ->rules(['numeric', 'min:0']),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add to Warehouse'),
            ])
            ->actions([
                DeleteAction::make(),
            ]);
    }
}
