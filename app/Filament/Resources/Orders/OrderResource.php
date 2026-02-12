<?php

namespace App\Filament\Resources\Orders;

use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\Schemas\OrderForm;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use BackedEnum;
use UnitEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Forms\Components\Select;
use App\Models\Product;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shopping-cart';

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                // 1. TOP ROW: Order Details & Grand Total
                Grid::make(3)->schema([
                    // LEFT: Order Details
                    Section::make('Order Details')
                        ->schema([
                            Grid::make(3)->schema([
                                TextInput::make('total_amount')
                                    ->label('GRAND TOTAL')
                                    ->prefix('₦')
                                    ->formatStateUsing(fn($state) => number_format($state ?? 0))
                                    ->readOnly()
                                    ->extraInputAttributes(['class' => 'text-2xl font-black text-primary-600']),
                                // A. The Table - CHANGED to Select
                                Select::make('table_id')
                                    ->label('Table')
                                    ->relationship('table', 'name') // Loads names, saves ID
                                    ->disabled() // Keeps it read-only
                                    ->dehydrated() // CRITICAL: Sends the ID even when disabled
                                    ->extraInputAttributes(['class' => 'text-xl font-bold text-primary-600']),

                                // B. Status - CHANGED to Select
                                Select::make('status')
                                    ->label('Status')
                                    ->options([
                                        'pending' => 'PENDING',
                                        'preparing' => 'PREPARING',
                                        'ready' => 'READY',
                                        'served' => 'SERVED',
                                        'paid' => 'PAID',
                                        'cancelled' => 'CANCELLED',
                                    ])
                                    ->disabled()
                                    ->dehydrated()
                                    ->extraInputAttributes(['class' => 'font-bold']),

                                // C. Server Name - CHANGED to Select
                                Select::make('user_id')
                                    ->label('Server')
                                    ->relationship('user', 'name')
                                    ->disabled()
                                    ->dehydrated(),
                            ]),
                        ])
                        ->columnSpan(2),
                ]),

                // 2. FULL WIDTH SECTION: The Items List
                Section::make('Order Items')
                    ->schema([
                        Repeater::make('items')
                            ->relationship()
                            ->schema([
                                // ... (I left this exactly as your original code) ...
                                Select::make('product_id')
                                    ->label('Product')
                                    ->options(Product::pluck('name', 'id'))
                                    ->required()
                                    ->searchable()
                                    ->live(debounce: 300)
                                    ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                        $product = Product::find($state);
                                        if ($product) {
                                            $set('product_name', $product->name);
                                            $set('unit_price', $product->price);
                                            $quantity = $get('quantity') ?? 1;
                                            $subtotal = $product->price * $quantity;
                                            $set('subtotal', $subtotal);
                                            self::recalculateTotal($get, $set);
                                        }
                                    })
                                    ->columnSpan(2),

                                TextInput::make('product_name')
                                    ->hidden()
                                    ->dehydrated(),

                                TextInput::make('quantity')
                                    ->label('Qty')
                                    ->numeric()
                                    ->default(1)
                                    ->required()
                                    ->minValue(1)
                                    ->live(debounce: 300)
                                    ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                        $unitPrice = $get('unit_price') ?? 0;
                                        $subtotal = $state * $unitPrice;
                                        $set('subtotal', $subtotal);
                                        self::recalculateTotal($get, $set);
                                    }),

                                TextInput::make('unit_price')
                                    ->label('Unit Price')
                                    ->numeric()
                                    ->prefix('₦')
                                    ->readOnly(),

                                TextInput::make('subtotal')
                                    ->label('Subtotal')
                                    ->numeric()
                                    ->prefix('₦')
                                    ->readOnly(),
                            ])
                            ->columns(6)
                            ->deleteAction(
                                fn(Action $action) => $action->after(function (Get $get, Set $set) {
                                    self::recalculateTotal($get, $set);
                                })
                            )
                            ->addAction(
                                fn(Action $action) => $action->after(function (Get $get, Set $set) {
                                    self::recalculateTotal($get, $set);
                                })
                            )
                            ->reorderable(false),
                    ])
                    ->columnSpanFull(),
            ])->columns(1);
    }

    // Improved helper method to recalculate total
    protected static function recalculateTotal(Get $get, Set $set): void
    {
        // Get the current repeater state
        $items = $get('../../items') ?? [];

        // Calculate total from all item subtotals
        $total = collect($items)->sum(function ($item) {
            return (float) ($item['subtotal'] ?? 0);
        });

        // Set the grand total
        $set('../../total_amount', $total);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['table', 'user', 'guest', 'processedByUser', 'items.product']);

        // Check if the user is NOT an admin (assuming admin has ID 1 or specific role)
        // Adjust 'waiter' to match your actual role name
        if (!auth()->user()->hasRole('super_admin')) {
            $query->where('user_id', auth()->id());
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order_number')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('table.name')
                    ->label('Table')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'pending' => 'gray',
                        'preparing' => 'warning',
                        'ready' => 'info',
                        'served' => 'success',
                        'paid' => 'success',
                        'partial' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('balance_due')
                    ->label('Debt')
                    ->money('NGN')
                    ->state(fn(Order $record): float => max(0, $record->total_amount - $record->amount_paid))
                    ->color('danger')
                    ->weight('bold')
                    ->state(function (Order $record) {
                        // 1. If Paid or Pending, return null (shows nothing)
                        if ($record->amount_paid >= $record->total_amount || $record->status === 'pending') {
                            return null;
                        }

                        // 2. Otherwise, return the calculation
                        return max(0, $record->total_amount - $record->amount_paid);
                    }),

                TextColumn::make('total_amount')
                    ->money('NGN')
                    ->sortable(),

                TextColumn::make('guest.name')
                    ->label('Debtor')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50, 100])
            ->recordActions([
                Action::make('edit')
                    ->icon('heroicon-o-pencil')
                    ->label('Edit')
                    ->url(
                        fn(Order $record): string => auth()->user()->can('update', $record)
                            ? Pages\EditOrder::getUrl(['record' => $record])
                            : Pages\ViewOrder::getUrl(['record' => $record])
                    ),
                Action::make('delete')
                    ->requiresConfirmation()
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->action(fn(Order $record) => $record->delete()),
            ])
            ->toolbarActions([
                BulkAction::make('delete')
                    ->icon('heroicon-o-trash')
                    ->label('Delete Selected')
                    ->requiresConfirmation()
                    ->color('danger')
                    ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                        Order::whereIn('id', $records->pluck('id')->toArray())->delete();
                    }),
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
            'view' => ViewOrder::route('/{record}'),
            'edit' => EditOrder::route('/{record}/edit'),
        ];
    }
}
