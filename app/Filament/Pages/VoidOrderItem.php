<?php

namespace App\Filament\Pages;

use App\Models\OrderItem;
use App\Services\PermissionService;
use App\Services\UnreturnableVoidService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * Manager-only. Explicitly NOT a return — no stock ever reverses here. This
 * is for an item that's already gone (comp, guest complaint, spillage after
 * serving) and just needs to stop counting against the waiter's remittance,
 * with a permanent reasoned record for reporting.
 */
class VoidOrderItem extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-refund';
    protected static string|UnitEnum|null $navigationGroup = 'Restaurant Management';
    protected static ?string $navigationLabel = 'Void Order Item';
    protected static ?string $title = 'Void Order Item (Supervisor Only)';
    protected string $view = 'filament.pages.void-order-item';

    public array $data = [];

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->schema([
                Select::make('order_item_id')
                    ->label('Order Item')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => OrderItem::query()
                        ->where('product_name', 'like', "%{$search}%")
                        ->whereHas('order', fn ($q) => $q->whereIn('status', ['served', 'ready', 'preparing', 'pending']))
                        ->limit(20)
                        ->get()
                        ->mapWithKeys(fn (OrderItem $item) => [$item->id => "#{$item->order_id} — {$item->product_name} (qty {$item->quantity})"]))
                    ->getOptionLabelUsing(fn ($value) => optional(OrderItem::find($value))->product_name)
                    ->required(),

                TextInput::make('quantity')
                    ->numeric()
                    ->required()
                    ->minValue(1),

                Select::make('reason_code')
                    ->options([
                        'comp' => 'Comp (goodwill)',
                        'complaint' => 'Guest Complaint',
                        'loss' => 'Loss / Spillage',
                        'other' => 'Other',
                    ])
                    ->required(),

                Textarea::make('notes'),
            ]);
    }

    public function apply(): void
    {
        $data = $this->form->getState();

        try {
            $item = OrderItem::findOrFail($data['order_item_id']);
            (new UnreturnableVoidService())->apply(
                $item,
                auth()->user(),
                $data['reason_code'],
                (int) $data['quantity'],
                $data['notes'] ?? null,
            );

            Notification::make()->title('Item voided')->success()->send();
            $this->form->fill();
        } catch (\Exception $e) {
            Notification::make()->title('Could not void item')->body($e->getMessage())->danger()->send();
        }
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('apply')
                ->label('Void Item')
                ->submit('apply'),
        ];
    }
}
