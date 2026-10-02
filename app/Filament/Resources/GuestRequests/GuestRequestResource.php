<?php

namespace App\Filament\Resources\GuestRequests;

use App\Filament\Resources\GuestRequests\Pages\ListGuestRequests;
use App\Filament\Resources\GuestRequests\Pages\ViewGuestRequest;
use App\Models\GuestRequest;
use App\Support\BusinessDay;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * "Guest Requests" (Phase 2) — a READ-ONLY window on what guests have sent
 * from their phones. Requests are only ever written by GuestRequestService;
 * there is no create, edit or delete here. Gated by GuestRequestPolicy
 * (Shield), seeded for super_admin and manager.
 */
class GuestRequestResource extends Resource
{
    protected static ?string $model = GuestRequest::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static string|UnitEnum|null $navigationGroup = 'Guest Ordering';

    protected static ?string $navigationLabel = 'Guest Requests';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'ref';

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

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['table', 'room']))
            ->columns([
                TextColumn::make('ref')->searchable()->weight('bold'),
                TextColumn::make('place')->label('Table / room')->state(fn (GuestRequest $r) => $r->placeLabel()),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => GuestRequest::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        GuestRequest::STATUS_PENDING => 'warning',
                        GuestRequest::STATUS_CONFIRMED, GuestRequest::STATUS_COMPLETED => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('total_snapshot')->label('Total')->money('NGN'),
                TextColumn::make('submitted_at')->label('Sent')->dateTime(),
                TextColumn::make('age')->label('Age')->state(fn (GuestRequest $r) => $r->submitted_at?->diffForHumans(short: true)),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(GuestRequest::STATUS_LABELS),
                Filter::make('business_day')
                    ->schema([DatePicker::make('day')->label('Business day')->default(BusinessDay::today())])
                    ->query(fn (Builder $query, array $data) => $query->when($data['day'] ?? null, fn ($q, $day) => $q->whereDate('business_date', $day)))
                    ->indicateUsing(fn (array $data) => ($data['day'] ?? null) ? 'Business day '.$data['day'] : null),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make()->schema([
                TextEntry::make('ref'),
                TextEntry::make('place')->label('Table / room')->state(fn (GuestRequest $r) => $r->placeLabel()),
                TextEntry::make('status')->formatStateUsing(fn (string $state) => GuestRequest::STATUS_LABELS[$state] ?? $state)->badge(),
                TextEntry::make('total_snapshot')->label('Total')->money('NGN'),
                TextEntry::make('submitted_at')->label('Sent')->dateTime(),
                TextEntry::make('business_date')->label('Business day')->date(),
                TextEntry::make('cancelled_at')->label('Cancelled')->dateTime()->placeholder('—'),
            ])->columns(4),
            Section::make('Lines')->schema([
                RepeatableEntry::make('items')->hiddenLabel()->schema([
                    TextEntry::make('quantity_requested')->label('Qty'),
                    TextEntry::make('name_snapshot')->label('Item'),
                    TextEntry::make('station')->badge(),
                    TextEntry::make('unit_price_snapshot')->label('Price')->money('NGN'),
                    TextEntry::make('chips')->label('Chips')->state(fn ($record) => $record->chips ? implode(' · ', $record->chips) : null)->placeholder('—'),
                    TextEntry::make('note')->placeholder('—'),
                    TextEntry::make('status')->badge(),
                ])->columns(7),
            ]),
            // Phase 4 — the sitting's claims, calls and moves. Read-only:
            // claims and moves are append-only and written by their services.
            Section::make('Payment claims (this sitting)')->collapsible()->schema([
                RepeatableEntry::make('sessionClaims')->hiddenLabel()->placeholder('No claims.')->schema([
                    TextEntry::make('payer_name')->label('Payer'),
                    TextEntry::make('amount')->money('NGN'),
                    TextEntry::make('transferAccount.bank_name')->label('Account')->placeholder('—'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('created_at')->label('Sent')->dateTime(),
                    TextEntry::make('status_set_at')->label('Settled')->dateTime()->placeholder('—'),
                ])->columns(6),
            ]),
            Section::make('Waiter calls (this sitting)')->collapsible()->schema([
                RepeatableEntry::make('sessionWaiterCalls')->hiddenLabel()->placeholder('No calls.')->schema([
                    TextEntry::make('reason')->formatStateUsing(fn (string $state) => \App\Models\GuestWaiterCall::REASONS[$state] ?? $state),
                    TextEntry::make('note')->placeholder('—'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('acknowledgedBy.name')->label('Answered by')->placeholder('—'),
                    TextEntry::make('created_at')->label('Called')->dateTime(),
                ])->columns(5),
            ]),
            Section::make('Table moves (this sitting)')->collapsible()->schema([
                RepeatableEntry::make('sessionMoves')->hiddenLabel()->placeholder('No moves.')->schema([
                    TextEntry::make('fromTable.name')->label('From'),
                    TextEntry::make('toTable.name')->label('To'),
                    TextEntry::make('order_ids')->label('Orders moved')->state(fn ($record) => count($record->order_ids ?? [])),
                    TextEntry::make('movedBy.name')->label('Moved by'),
                    TextEntry::make('created_at')->label('When')->dateTime(),
                ])->columns(5),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGuestRequests::route('/'),
            'view' => ViewGuestRequest::route('/{record}'),
        ];
    }
}
