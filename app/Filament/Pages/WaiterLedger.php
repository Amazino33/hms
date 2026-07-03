<?php

namespace App\Filament\Pages;

use App\Models\OrderItem;
use App\Models\Shift;
use App\Models\User;
use App\Services\PermissionService;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class WaiterLedger extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationLabel = 'Waiter Ledger';
    protected static ?string $title = 'Waiter Ledger';
    protected string $view = 'filament.pages.waiter-ledger';

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    public string $viewMode = 'flat';

    public string $tallyDate;

    public string $tallyDestination = 'bar';

    /**
     * Rotating header colors for the per-waiter tally columns — purely
     * cosmetic, matches the look of the old handover spreadsheet.
     */
    protected array $tallyPalette = [
        ['bg' => 'bg-amber-100 dark:bg-amber-900/30', 'text' => 'text-amber-800 dark:text-amber-300'],
        ['bg' => 'bg-blue-100 dark:bg-blue-900/30', 'text' => 'text-blue-800 dark:text-blue-300'],
        ['bg' => 'bg-emerald-100 dark:bg-emerald-900/30', 'text' => 'text-emerald-800 dark:text-emerald-300'],
        ['bg' => 'bg-pink-100 dark:bg-pink-900/30', 'text' => 'text-pink-800 dark:text-pink-300'],
        ['bg' => 'bg-violet-100 dark:bg-violet-900/30', 'text' => 'text-violet-800 dark:text-violet-300'],
        ['bg' => 'bg-cyan-100 dark:bg-cyan-900/30', 'text' => 'text-cyan-800 dark:text-cyan-300'],
    ];

    public function mount(): void
    {
        $this->tallyDate = now()->format('Y-m-d');
    }

    public function setViewMode(string $mode): void
    {
        $this->viewMode = in_array($mode, ['flat', 'columns'], true) ? $mode : 'flat';
    }

    /**
     * Per-waiter daily tally — the digital equivalent of the old one-column-
     * per-waiter bartender handover sheet. Grouped by whoever took the
     * order (not who processed payment), for the selected day/destination.
     */
    public function getTallyColumnsProperty(): array
    {
        $query = OrderItem::query()
            ->with(['order.user'])
            ->whereHas('order', function ($q) {
                $q->whereDate('created_at', $this->tallyDate)
                    ->where('status', '!=', 'cancelled');

                if ($this->tallyDestination !== '') {
                    $q->where('destination', $this->tallyDestination);
                }
            });

        $items = $query->get()->filter(fn (OrderItem $item) => $item->order?->user_id);

        $columns = $items->groupBy('order.user_id')->map(function ($waiterItems) {
            $waiter = $waiterItems->first()->order->user;

            return [
                'waiter' => $waiter,
                'items' => $waiterItems->sortBy('created_at')->values(),
                'total' => $waiterItems->sum('subtotal'),
            ];
        })->sortByDesc('total')->values();

        return $columns->map(function ($column, $index) {
            $column['color'] = $this->tallyPalette[$index % count($this->tallyPalette)];

            return $column;
        })->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                OrderItem::query()->with(['order.table', 'order.user'])
            )
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('order.table.name')
                    ->label('Table')
                    ->placeholder('Takeaway')
                    ->weight('bold')
                    ->searchable(),

                TextColumn::make('created_at')
                    ->label('Date/Time')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),

                TextColumn::make('order.user.name')
                    ->label('Waiter')
                    ->searchable(),

                TextColumn::make('product_name')
                    ->label('Item')
                    ->searchable(),

                TextColumn::make('quantity')
                    ->label('Qty')
                    ->numeric(),

                TextColumn::make('unit_price')
                    ->label('Unit Price')
                    ->money('NGN'),

                TextColumn::make('subtotal')
                    ->label('Line Total')
                    ->money('NGN'),

                TextColumn::make('order.status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'paid' => 'success',
                        'partial' => 'danger',
                        'served', 'ready', 'preparing', 'pending' => 'warning',
                        'cancelled' => 'gray',
                        'returned' => 'info',
                        default => 'gray',
                    }),

                TextColumn::make('order.order_number')
                    ->label('Order #')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->groups([
                Group::make('created_at')
                    ->label('Day')
                    ->date(),
            ])
            ->filters([
                Filter::make('date_range')
                    ->label('Date Range')
                    ->form([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),

                SelectFilter::make('shift')
                    ->label('Shift')
                    ->options(fn () => Shift::query()
                        ->with('user')
                        ->orderByDesc('started_at')
                        ->limit(100)
                        ->get()
                        ->mapWithKeys(fn (Shift $shift) => [
                            $shift->id => ($shift->user->name ?? 'Unknown') . ' — ' . $shift->started_at->format('M j, g:i A'),
                        ]))
                    ->query(fn (Builder $query, array $data): Builder => isset($data['value']) && $data['value'] !== ''
                        ? $query->whereHas('order', fn ($q) => $q->where('shift_id', $data['value']))
                        : $query),

                SelectFilter::make('waiter')
                    ->label('Waiter')
                    ->options(fn () => User::whereHas('roles', fn ($q) => $q->whereIn('name', ['waiter', 'porter']))->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data): Builder => isset($data['value']) && $data['value'] !== ''
                        ? $query->whereHas('order', fn ($q) => $q->where('user_id', $data['value']))
                        : $query),

                SelectFilter::make('destination')
                    ->options(['bar' => 'Bar', 'kitchen' => 'Kitchen', 'main' => 'Main'])
                    ->query(fn (Builder $query, array $data): Builder => isset($data['value']) && $data['value'] !== ''
                        ? $query->whereHas('order', fn ($q) => $q->where('destination', $data['value']))
                        : $query),

                SelectFilter::make('payment_status')
                    ->label('Payment Status')
                    ->options([
                        'paid' => 'Paid',
                        'partial' => 'Partial / Debt',
                        'served' => 'Served (Unpaid)',
                        'cancelled' => 'Cancelled',
                        'returned' => 'Returned',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => isset($data['value']) && $data['value'] !== ''
                        ? $query->whereHas('order', fn ($q) => $q->where('status', $data['value']))
                        : $query),
            ])
            ->headerActions([
                ExportAction::make()
                    ->exports([
                        ExcelExport::make()
                            ->fromTable()
                            ->withFilename('waiter-ledger-' . now()->format('Y-m-d')),
                    ]),
            ]);
    }
}
