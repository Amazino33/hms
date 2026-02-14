<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\Action;
use App\Services\PermissionService;
use BackedEnum;
use UnitEnum;
use Filament\Notifications\Notification;

class QuickInventoryUpdate extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';
    protected static string|UnitEnum|null $navigationGroup = 'Inventory';
    protected static ?string $title = 'Quick Inventory Update';
    protected static ?int $navigationSort = 15;
    protected string $view = 'filament.pages.quick-inventory-update';

    public ?int $selectedWarehouseId = null;

    public function mount(): void
    {
        // Default to first warehouse
        $this->selectedWarehouseId = Warehouse::first()?->id;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Product::query()
                    ->with(['inventory' => function ($query) {
                        if ($this->selectedWarehouseId) {
                            $query->where('warehouse_id', $this->selectedWarehouseId);
                        }
                    }])
                    ->orderBy('name')
            )
            ->columns([
                // Product Name
                TextColumn::make('name')
                    ->label('Product Name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                // Product SKU
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable(),

                // Current Stock (Read-only)
                TextColumn::make('inventory.quantity')
                    ->label('Current Stock')
                    ->default(0)
                    ->numeric()
                    ->color('primary'),

                // Reorder Level
                TextColumn::make('reorder_level')
                    ->label('Reorder Level')
                    ->numeric()
                    ->color(fn ($record) => $record->inventory->first()?->quantity <= $record->reorder_level ? 'danger' : 'success'),

                // Unit Price
                TextColumn::make('price')
                    ->label('Cost Price')
                    ->money('NGN')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('warehouse')
                    ->options(Warehouse::pluck('name', 'id'))  // Use options instead of relationship to avoid joins
                    ->default($this->selectedWarehouseId)
                    ->query(function ($query, $value) {
                        if ($value) {
                            $this->selectedWarehouseId = $value;
                        }
                    }),
            ])
            ->actions([
                Action::make('add_stock')
                    ->label('Add Stock')
                    ->icon('heroicon-m-plus-circle')
                    ->color('success')
                    ->form([
                        TextInput::make('quantity')
                            ->label('Quantity to Add')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->placeholder('Enter quantity'),
                        
                        TextInput::make('cost_per_unit')
                            ->label('Cost Per Unit (₦)')
                            ->numeric()
                            ->required()
                            ->placeholder('Enter cost price'),
                        
                        Select::make('reference')
                            ->label('Purchase Reference')
                            ->options([
                                'invoice' => 'Invoice #',
                                'po' => 'Purchase Order',
                                'manual' => 'Manual Adjustment',
                            ])
                            ->required(),
                        
                        TextInput::make('reference_number')
                            ->label('Reference Number')
                            ->required()
                            ->placeholder('e.g., INV-001 or PO-123'),
                    ])
                    ->action(function (Product $record, array $data) {
                        $warehouse = Warehouse::find($this->selectedWarehouseId);
                        
                        if (!$warehouse) {
                            Notification::make()
                                ->danger()
                                ->title('Error')
                                ->body('Please select a warehouse first')
                                ->send();
                            return;
                        }

                        // Update inventory
                        $inventory = $record->inventory()
                            ->where('warehouse_id', $warehouse->id)
                            ->first();

                        if ($inventory) {
                            $inventory->quantity += $data['quantity'];
                            $inventory->save();
                        } else {
                            $record->inventory()->create([
                                'warehouse_id' => $warehouse->id,
                                'quantity' => $data['quantity'],
                            ]);
                        }

                        // Log the transaction
                        \App\Models\InventoryTransaction::create([
                            'product_id' => $record->id,
                            'warehouse_id' => $warehouse->id,
                            'type' => 'purchase',
                            'quantity' => $data['quantity'],
                            'reference' => $data['reference'] . '_' . $data['reference_number'],
                            'cost_per_unit' => $data['cost_per_unit'],
                            'user_id' => auth()->id(),
                        ]);

                        Notification::make()
                            ->success()
                            ->title('Stock Updated')
                            ->body("{$record->name}: +{$data['quantity']} units added")
                            ->send();
                    }),
            ])
            ->striped()
            ->paginated([10, 25, 50]);
    }

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }
}