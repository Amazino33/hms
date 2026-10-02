<?php

namespace App\Filament\Resources\GuestDeliveryRefusals;

use App\Filament\Resources\GuestDeliveryRefusals\Pages\ListGuestDeliveryRefusals;
use App\Models\GuestDeliveryRefusal;
use App\Services\Guest\RoomDeliveryService;
use App\Services\UserFeedback;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * "Delivery refusals" (Phase 5, D26): a guest refused a room delivery.
 * Read-only apart from the manager's decision — approve (the room order is
 * cancelled and its folio charge reversed) or reject (the charge stands).
 * Drinks can't be decided until the bar has confirmed they came back.
 * Gated by GuestDeliveryRefusalPolicy (Shield), seeded for super_admin and
 * manager.
 */
class GuestDeliveryRefusalResource extends Resource
{
    protected static ?string $model = GuestDeliveryRefusal::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-hand-raised';

    protected static string|UnitEnum|null $navigationGroup = 'Guest Ordering';

    protected static ?string $navigationLabel = 'Delivery Refusals';

    protected static ?int $navigationSort = 4;

    public const STATUS_LABELS = [
        GuestDeliveryRefusal::AWAITING_BAR_RETURN => 'Waiting for the bar',
        GuestDeliveryRefusal::AWAITING_MANAGER => 'Waiting for a manager',
        GuestDeliveryRefusal::APPROVED => 'Approved — reversed',
        GuestDeliveryRefusal::REJECTED => 'Rejected — charge stands',
    ];

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $open = GuestDeliveryRefusal::where('status', GuestDeliveryRefusal::AWAITING_MANAGER)->count();

        return $open ? (string) $open : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['request.room', 'order.items', 'recordedBy', 'decidedBy']))
            ->columns([
                TextColumn::make('room')->label('Room')->state(fn (GuestDeliveryRefusal $r) => 'Room '.$r->request?->room?->number)->weight('bold'),
                TextColumn::make('request.ref')->label('Ref'),
                TextColumn::make('items')->label('Items')->state(fn (GuestDeliveryRefusal $r) => $r->order?->items->map(fn ($i) => $i->quantity.'× '.$i->product_name)->join(', ')),
                TextColumn::make('order.total_amount')->label('Charge')->money('NGN'),
                TextColumn::make('station')->badge(),
                TextColumn::make('reason')->wrap(),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => self::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        GuestDeliveryRefusal::AWAITING_MANAGER => 'warning',
                        GuestDeliveryRefusal::AWAITING_BAR_RETURN => 'info',
                        GuestDeliveryRefusal::APPROVED => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('recordedBy.name')->label('Recorded by'),
                TextColumn::make('created_at')->label('When')->dateTime(),
                TextColumn::make('decidedBy.name')->label('Decided by')->placeholder('—'),
                TextColumn::make('decision_note')->label('Note')->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(self::STATUS_LABELS),
            ])
            ->recordActions([
                self::decision('approve', 'Approve — reverse the charge', 'success', true),
                self::decision('reject', 'Reject — charge stands', 'danger', false),
            ])
            ->toolbarActions([]);
    }

    private static function decision(string $name, string $label, string $color, bool $approve): Action
    {
        return Action::make($name)
            ->label($label)
            ->color($color)
            ->requiresConfirmation()
            ->schema([Textarea::make('note')->label('Note (optional)')->maxLength(255)])
            ->visible(fn (GuestDeliveryRefusal $record) => $record->status === GuestDeliveryRefusal::AWAITING_MANAGER
                && auth()->user()?->can('update', $record))
            ->action(function (GuestDeliveryRefusal $record, array $data) use ($approve) {
                try {
                    (new RoomDeliveryService)->decide($record, auth()->user(), $approve, $data['note'] ?? null);
                } catch (\Exception $e) {
                    UserFeedback::blocked('Not decided', $e->getMessage());

                    return;
                }

                UserFeedback::succeeded($approve ? 'Refusal approved — charge reversed' : 'Refusal rejected — charge stands');
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGuestDeliveryRefusals::route('/'),
        ];
    }
}
