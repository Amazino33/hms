<?php

namespace App\Filament\Resources\StockAdjustments;

use App\Filament\Resources\StockAdjustments\Pages\CreateStockAdjustment;
use App\Filament\Resources\StockAdjustments\Pages\ListStockAdjustments;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\WareHouse;
use App\Services\StockAdjustmentService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class StockAdjustmentResource extends Resource
{
    protected static ?string $model = StockAdjustment::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';
    protected static string|UnitEnum|null $navigationGroup = 'Inventory';
    protected static ?string $navigationLabel = 'Stock Adjustments';
    protected static ?string $recordTitleAttribute = 'id';

    public const REASONS = [
        'damage' => 'Damage',
        'spillage_wastage' => 'Spillage / Wastage',
        'theft_suspected' => 'Suspected Theft',
        'expiry' => 'Expiry',
        'count_correction' => 'Count Correction',
        'other' => 'Other',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Select::make('item_type')
                ->options(['product' => 'Product', 'ingredient' => 'Ingredient'])
                ->required()
                ->live()
                ->afterStateUpdated(fn (callable $set) => $set('item_id', null)),

            Select::make('item_id')
                ->label('Item')
                ->options(fn (callable $get) => $get('item_type') === 'ingredient'
                    ? Ingredient::pluck('name', 'id')
                    : Product::pluck('name', 'id'))
                ->searchable()
                ->required(),

            Select::make('warehouse_id')
                ->label('Warehouse')
                ->options(fn () => WareHouse::pluck('name', 'id'))
                ->required(),

            TextInput::make('quantity_change')
                ->label('Quantity Change')
                ->numeric()
                ->required()
                ->helperText('Positive to increase stock, negative to decrease it (e.g. -5 for 5 units damaged).'),

            Select::make('reason')
                ->options(self::REASONS)
                ->required()
                ->live(),

            Textarea::make('notes')
                ->label('Notes')
                ->required(fn (callable $get) => $get('reason') === 'other')
                ->helperText('Required when reason is "Other".'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('#'),
                TextColumn::make('item_type')
                    ->label('Type')
                    ->badge(),
                TextColumn::make('item_name')
                    ->label('Item')
                    ->state(fn (StockAdjustment $record) => $record->item_type === 'product'
                        ? ($record->product?->name ?? '—')
                        : ($record->ingredient?->name ?? '—')),
                TextColumn::make('warehouse.name')
                    ->label('Warehouse'),
                TextColumn::make('quantity_change')
                    ->label('Change')
                    ->numeric()
                    ->color(fn (StockAdjustment $record) => $record->quantity_change >= 0 ? 'success' : 'danger'),
                TextColumn::make('reason')
                    ->formatStateUsing(fn (string $state) => self::REASONS[$state] ?? $state),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'pending' => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('requestedBy.name')
                    ->label('Requested By'),
                TextColumn::make('reviewedBy.name')
                    ->label('Reviewed By')
                    ->default('—'),
                TextColumn::make('created_at')
                    ->label('Requested At')
                    ->dateTime('M j, Y g:i A'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected']),
                SelectFilter::make('item_type')
                    ->options(['product' => 'Product', 'ingredient' => 'Ingredient']),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->color('success')
                    ->icon('heroicon-o-check-circle')
                    ->requiresConfirmation()
                    ->visible(fn (StockAdjustment $record) => $record->isPending()
                        && $record->requested_by !== auth()->id()
                        && auth()->user()->can('update', $record))
                    ->action(function (StockAdjustment $record) {
                        try {
                            (new StockAdjustmentService())->approve($record, auth()->user());

                            Notification::make()->title('Adjustment approved')->success()->send();
                        } catch (\Exception $e) {
                            Notification::make()->title('Could not approve')->body($e->getMessage())->danger()->persistent()->send();
                        }
                    }),

                Action::make('reject')
                    ->label('Reject')
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->form([
                        Textarea::make('rejection_reason')->required()->label('Reason for rejection'),
                    ])
                    ->visible(fn (StockAdjustment $record) => $record->isPending()
                        && $record->requested_by !== auth()->id()
                        && auth()->user()->can('update', $record))
                    ->action(function (StockAdjustment $record, array $data) {
                        try {
                            (new StockAdjustmentService())->reject($record, auth()->user(), $data['rejection_reason']);

                            Notification::make()->title('Adjustment rejected')->success()->send();
                        } catch (\Exception $e) {
                            Notification::make()->title('Could not reject')->body($e->getMessage())->danger()->persistent()->send();
                        }
                    }),
            ])
            ->headerActions([
                CreateAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockAdjustments::route('/'),
            'create' => CreateStockAdjustment::route('/create'),
        ];
    }
}
