<?php

namespace App\Filament\Resources\Orders;

use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Schemas\OrderForm;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use App\Models\Product;
use Filament\Schemas\Components\Section as ComponentsSection;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                // SECTION 1: Order Details
                ComponentsSection::make('Order Information')->schema([
                    TextInput::make('order_number')
                        ->default('ORD-' . time())
                        ->required()
                        ->readOnly(), // Auto-generated

                    Select::make('status')
                        ->options([
                            'pending' => 'Pending',
                            'preparing' => 'Preparing',
                            'ready' => 'Ready',
                            'served' => 'Served',
                            'paid' => 'Paid',
                            'cancelled' => 'Cancelled',
                        ])
                        ->default('pending')
                        ->required(),

                    Select::make('payment_method')
                        ->options([
                            'cash' => 'Cash',
                            'transfer' => 'Bank Transfer',
                            'pos' => 'POS Machine',
                        ])
                        ->default('cash'),
                        
                    TextInput::make('total_amount')
                        ->numeric()
                        ->prefix('₦')
                        ->default(0)
                        ->required(),
                ])->columns(2),

                // SECTION 2: The Items (Repeater)
                ComponentsSection::make('Order Items')->schema([
                    Repeater::make('items')
                        ->relationship() // Uses the 'items' relationship in Order Model
                        ->schema([
                            Select::make('product_id')
                                ->label('Product')
                                ->options(Product::all()->pluck('name', 'id'))
                                ->required()
                                ->searchable()
                                ->reactive() // Update price when product changes
                                ->afterStateUpdated(function ($state, Set $set) {
                                    $product = Product::find($state);
                                    if ($product) {
                                        $set('unit_price', $product->price);
                                        $set('product_name', $product->name);
                                    }
                                }),

                            // Hidden field to store name (for history)
                            TextInput::make('product_name')
                                ->hidden()
                                ->dehydrated(),

                            TextInput::make('quantity')
                                ->numeric()
                                ->default(1)
                                ->required()
                                ->reactive()
                                ->afterStateUpdated(fn ($state, Get $get, Set $set) => 
                                    $set('subtotal', $state * $get('unit_price'))
                                ),

                            TextInput::make('unit_price')
                                ->numeric()
                                ->label('Price')
                                ->readOnly(),

                            TextInput::make('subtotal')
                                ->numeric()
                                ->readOnly(),
                        ])
                        ->columns(4)
                        // Optional: Update total_amount when items are added (requires more advanced logic, 
                        // but for now, you can type the total manually at the top).
                ]), 
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
        ->columns([
            TextColumn::make('order_number')
                ->searchable()
                ->sortable()
                ->weight('bold'),

            TextColumn::make('total_amount')
                ->money('NGN')
                ->sortable(),

            TextColumn::make('status')
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    'pending' => 'gray',
                    'preparing' => 'warning',
                    'ready' => 'info',
                    'served' => 'success',
                    'paid' => 'success',
                    default => 'gray',
                }),

            TextColumn::make('created_at')
                ->dateTime()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
        ])
        ->defaultSort('created_at', 'desc')
        ->recordActions([
            Action::make('edit')
                ->icon('heroicon-o-pencil')
                ->label('Edit')
                ->url(fn (Order $record) => EditOrder::getUrl(['record' => $record])),            
            Action::make('delete')
                ->requiresConfirmation()
                ->label('Delete')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->action(fn (Order $record) => $record->delete()),
        ])
        ->toolbarActions([
            BulkAction::make('delete')
                ->icon('heroicon-o-trash')
                ->label('Delete Selected')
                ->requiresConfirmation()
                ->color('danger')
                ->action(fn (array $records) => Order::whereIn('id', $records)->delete()),
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
            'index' => ListOrders::route('/'),
            'create' => CreateOrder::route('/create'),
            'edit' => EditOrder::route('/{record}/edit'),
        ];
    }
}
