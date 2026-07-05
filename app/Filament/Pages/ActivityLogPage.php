<?php

namespace App\Filament\Pages;

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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;
use UnitEnum;

class ActivityLogPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';
    protected static string|UnitEnum|null $navigationGroup = 'Administration';
    protected static ?string $navigationLabel = 'Activity Log';
    protected static ?string $title = 'Activity Log';
    protected string $view = 'filament.pages.activity-log-page';

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    /**
     * Every subject/causer model class currently wired to LogsActivity, used
     * to build a human-readable "Record Type" filter. Kept as a plain map
     * rather than reflecting the log table, since new models are added here
     * deliberately as they opt in to logging.
     */
    protected function subjectTypeOptions(): array
    {
        return [
            \App\Models\Product::class => 'Product',
            \App\Models\MenuItem::class => 'Menu Item',
            \App\Models\Category::class => 'Category',
            \App\Models\Order::class => 'Order',
            \App\Models\OrderPayment::class => 'Order Payment',
            \App\Models\Commission::class => 'Commission',
            \App\Models\Shift::class => 'Shift',
            \App\Models\StaffDebt::class => 'Staff Debt',
            \App\Models\StaffDebtRepayment::class => 'Staff Debt Repayment',
            \App\Models\StockTransfer::class => 'Stock Transfer',
            \App\Models\InventoryTransaction::class => 'Inventory Transaction',
            \App\Models\Ingredient::class => 'Ingredient',
            \App\Models\User::class => 'User',
            \Spatie\Permission\Models\Role::class => 'Role',
            \Spatie\Permission\Models\Permission::class => 'Permission',
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Activity::query()->with(['causer', 'subject']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('M j, Y g:i:s A')
                    ->sortable(),

                TextColumn::make('log_name')
                    ->label('Category')
                    ->badge()
                    ->searchable(),

                TextColumn::make('event')
                    ->label('Event')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'created' => 'success',
                        'updated' => 'warning',
                        'deleted' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('description')
                    ->label('Description')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('subject_type')
                    ->label('Record Type')
                    ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '—')
                    ->toggleable(),

                TextColumn::make('subject_id')
                    ->label('Record #')
                    ->toggleable(),

                TextColumn::make('causer.name')
                    ->label('Performed By')
                    ->default('System')
                    ->searchable(),
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

                SelectFilter::make('log_name')
                    ->label('Category')
                    ->options(fn () => Activity::query()
                        ->whereNotNull('log_name')
                        ->distinct()
                        ->orderBy('log_name')
                        ->pluck('log_name', 'log_name')
                        ->all()),

                SelectFilter::make('event')
                    ->options([
                        'created' => 'Created',
                        'updated' => 'Updated',
                        'deleted' => 'Deleted',
                    ]),

                SelectFilter::make('subject_type')
                    ->label('Record Type')
                    ->options($this->subjectTypeOptions()),

                SelectFilter::make('causer_id')
                    ->label('Performed By')
                    ->options(fn () => User::orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data): Builder => isset($data['value']) && $data['value'] !== ''
                        ? $query->where('causer_id', $data['value'])->where('causer_type', User::class)
                        : $query),
            ]);
    }
}
