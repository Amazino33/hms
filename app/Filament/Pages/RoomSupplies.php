<?php

namespace App\Filament\Pages;

use App\Models\RoomSupply;
use App\Models\WareHouse;
use App\Services\PermissionService;
use App\Services\RoomSupplyService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Attributes\Locked;
use UnitEnum;

/**
 * The housekeeping-supplies (tissue, soap, etc.) catalog and stock —
 * mirrors QuickInventoryUpdate's "Add Stock" pattern exactly, on the
 * room-supplies stock track instead of Product. New catalog entries are
 * created here too (Product/Ingredient have their own dedicated resources
 * for that; room supplies don't need a full CRUD resource for something
 * this simple).
 */
class RoomSupplies extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $title = 'Room Supplies';

    protected string $view = 'filament.pages.room-supplies';

    public bool $ready = false;

    #[Locked]
    public ?int $selectedWarehouseId = null;

    public function load(): void
    {
        $this->ready = true;
    }

    public function mount(): void
    {
        $this->selectedWarehouseId = WareHouse::where('type', 'storage')->first()?->id;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                RoomSupply::query()
                    ->with(['inventory' => function ($query) {
                        if ($this->selectedWarehouseId) {
                            $query->where('warehouse_id', $this->selectedWarehouseId);
                        }
                    }])
                    ->orderBy('name')
            )
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('bold'),
                TextColumn::make('unit')->label('Unit'),
                TextColumn::make('inventory.quantity')->label('Current Stock')->default(0)->numeric(),
                TextColumn::make('cost_per_unit')->label('Cost Per Unit')->money('NGN')->sortable(),
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
                            ->minValue(1),
                        TextInput::make('cost_per_unit')
                            ->label('Cost Per Unit (₦)')
                            ->numeric()
                            ->required(),
                        Select::make('reference')
                            ->label('Purchase Reference')
                            ->options(['invoice' => 'Invoice #', 'po' => 'Purchase Order', 'manual' => 'Manual Adjustment'])
                            ->required(),
                        TextInput::make('reference_number')
                            ->label('Reference Number')
                            ->required(),
                    ])
                    ->action(function (RoomSupply $record, array $data) {
                        $warehouse = WareHouse::find($this->selectedWarehouseId);

                        if (! $warehouse) {
                            Notification::make()->danger()->title('Error')->body('No storage warehouse configured.')->send();

                            return;
                        }

                        try {
                            app(RoomSupplyService::class)->recordPurchase(
                                $record,
                                $warehouse,
                                (float) $data['quantity'],
                                (float) $data['cost_per_unit'],
                                auth()->id(),
                                $data['reference'].'_'.$data['reference_number'],
                            );

                            Notification::make()->success()->title('Stock Updated')->body("{$record->name}: +{$data['quantity']} {$record->unit} added")->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Could not add stock')->body($e->getMessage())->send();
                        }
                    }),
            ])
            ->headerActions([
                Action::make('new_room_supply')
                    ->label('New Room Supply')
                    ->icon('heroicon-o-plus')
                    ->form([
                        TextInput::make('name')->required(),
                        TextInput::make('unit')->label('Unit (e.g. roll, bar, pack)')->required()->default('unit'),
                        TextInput::make('cost_per_unit')->label('Starting Cost Per Unit (₦)')->numeric()->required()->default(0),
                    ])
                    ->action(function (array $data) {
                        RoomSupply::create($data);

                        Notification::make()->success()->title('Room supply created')->send();
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
