<?php

namespace App\Filament\Resources\MenuItems;

use App\Filament\Resources\MenuItems\Pages\CreateMenuItem;
use App\Filament\Resources\MenuItems\Pages\EditMenuItem;
use App\Filament\Resources\MenuItems\Pages\ListMenuItems;
use App\Filament\Resources\MenuItems\Pages\ViewMenuItem;
use App\Filament\Resources\MenuItems\Schemas\MenuItemInfolist;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\Category;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use UnitEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\BooleanColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MenuItemResource extends Resource
{
    protected static ?string $model = MenuItem::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string|UnitEnum|null $navigationGroup = 'Menu Management';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            TextInput::make('name')->required(),
            TextInput::make('sku')->required()->unique(ignoreRecord: true),
            Select::make('category_id')
                ->label('Category')
                ->options(Category::pluck('name', 'id'))
                ->searchable()
                ->required(),
            Hidden::make('type')->default('food'),
            TextInput::make('sale_price')->numeric()->required(),
            Toggle::make('available_for_sale')->default(true),
            Repeater::make('recipes')
                ->relationship('recipes')
                ->schema([
                    Select::make('ingredient_id')
                        ->label('Ingredient')
                        ->options(Ingredient::pluck('name', 'id'))
                        ->required()
                        ->searchable(),
                    TextInput::make('quantity_needed')
                        ->label('Quantity Needed')
                        ->numeric()
                        ->required(),
                ])
                ->columns(2)
                ->addActionLabel('+ Add Ingredient')
                ->collapsible(),
            Placeholder::make('total_recipe_cost')
                ->label('Total Recipe Cost')
                ->content(fn ($get) => '₦' . collect($get('recipes'))->sum(function ($recipe) {
                    $ingredient = Ingredient::find($recipe['ingredient_id']);
                    return $ingredient ? $recipe['quantity_needed'] * $ingredient->cost_per_unit : 0;
                })),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return MenuItemInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        
        return $table->columns([
            TextColumn::make('name')->searchable(),
            TextColumn::make('sku'),
            TextColumn::make('sale_price')->money('NGN'),
            TextColumn::make('total_recipe_cost')->money('NGN'),
            IconColumn::make('type')->boolean(),
        ])->actions([
            EditAction::make(),
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
            'index' => ListMenuItems::route('/'),
            'create' => CreateMenuItem::route('/create'),
            'view' => ViewMenuItem::route('/{record}'),
            'edit' => EditMenuItem::route('/{record}/edit'),
        ];
    }
}
