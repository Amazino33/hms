<?php
namespace App\Filament\Resources\Products;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Filament\Resources\Products\Tables\ProductsTable;
use App\Models\Product;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema; // Already present, used for schema definition
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\Schemas\Components\Section; // Section is in Schemas
use Filament\Forms\Components\TextInput; // Fields are in Forms
use Filament\Forms\Components\Select; // Fields are in Forms
use Filament\Forms\Components\Toggle; // Fields are in Forms


class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with(['category']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make()->schema([
                TextInput::make('name')->required(),
                TextInput::make('sku')->label('SKU (Barcode)'),
                Select::make('category_id')
                    ->relationship('category', 'name') // Magic: Auto-fetches Category names
                    ->required()
                    ->createOptionForm([ // Magic: Allows creating a Category inside the Product form!
                        TextInput::make('name')->required(),
                        Select::make('type')->options(['food'=>'Food', 'drink'=>'Drink'])->required(),
                    ]),
            ])->columns(2),

            Section::make()->schema([
                TextInput::make('price')->numeric()->prefix('₦')->required(),
                TextInput::make('cost_price')
                    ->label('Cost Price')
                    ->numeric()
                    ->prefix('₦')
                    ->required()
                    ->default(0),
                Toggle::make('is_active')->default(true),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\InventoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}