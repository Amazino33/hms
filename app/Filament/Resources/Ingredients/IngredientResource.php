<?php

namespace App\Filament\Resources\Ingredients;

use App\Filament\Resources\Ingredients\Pages\CreateIngredient;
use App\Filament\Resources\Ingredients\Pages\EditIngredient;
use App\Filament\Resources\Ingredients\Pages\ListIngredients;
use App\Filament\Resources\Ingredients\Pages\ViewIngredient;
use App\Filament\Resources\Ingredients\Schemas\IngredientForm;
use App\Filament\Resources\Ingredients\Schemas\IngredientInfolist;
use App\Filament\Resources\Ingredients\Tables\IngredientsTable;
use App\Models\Ingredient;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class IngredientResource extends Resource
{
    protected static ?string $model = Ingredient::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-beaker';

    protected static string|UnitEnum|null $navigationGroup = 'Menu Management';
    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            TextInput::make('name')->required(),
            TextInput::make('sku')->required()->unique(ignoreRecord: true),
            TextInput::make('unit_name')->required(),
            TextInput::make('quantity')->numeric()->required(),
            TextInput::make('cost_per_unit')->numeric()->required(),
            TextInput::make('category')->required(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return IngredientInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable(),
            TextColumn::make('sku'),
            TextColumn::make('category'),
            TextColumn::make('quantity')->numeric(),
            TextColumn::make('cost_per_unit')->money('NGN'),
        ])->actions([
            ViewAction::make(),
            EditAction::make(),
        ])->headerActions([
            CreateAction::make()->modalHeading('Add Kitchen Ingredient'),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIngredients::route('/'),
            'create' => CreateIngredient::route('/create'),
            'view' => ViewIngredient::route('/{record}'),
            'edit' => EditIngredient::route('/{record}/edit'),
        ];
    }
    
    public static function canAccess(): bool
    {
        return auth()->user()->hasRole(['super_admin', 'chef']);
    }
}
