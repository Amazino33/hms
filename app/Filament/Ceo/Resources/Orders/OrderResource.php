<?php

namespace App\Filament\Ceo\Resources\Orders;

use App\Filament\Ceo\Concerns\CeoReadOnlyResource;
use App\Filament\Ceo\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use BackedEnum;
use UnitEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrderResource extends Resource
{
    use CeoReadOnlyResource;

    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static string|UnitEnum|null $navigationGroup = 'Records';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('order_number')->searchable(),
                TextColumn::make('origin_label')->label('Origin'),
                TextColumn::make('user.name')->label('Waiter')->searchable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('total_amount')->money('NGN')->sortable(),
                TextColumn::make('payment_method')->label('Payment'),
                TextColumn::make('created_at')->dateTime('M j, Y g:ia')->sortable(),
            ])
            ->filters([
                Filter::make('date_range')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from'),
                        \Filament\Forms\Components\DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))),
                SelectFilter::make('status')->options([
                    'pending' => 'Pending', 'preparing' => 'Preparing', 'ready' => 'Ready', 'served' => 'Served',
                    'paid' => 'Paid', 'partial' => 'Partial', 'cancelled' => 'Cancelled', 'returned' => 'Returned',
                ]),
            ])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
        ];
    }
}
