<?php

namespace App\Filament\Resources\GuestContacts;

use App\Filament\Resources\GuestContacts\Pages\ListGuestContacts;
use App\Models\GuestContact;
use App\Services\UserFeedback;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * "Guest Contacts" (Phase 5, D27): WhatsApp numbers reception saved from
 * room-order chats. Managers only (GuestContactPolicy, seeded for
 * super_admin and manager). Read-only, except that specials may be turned
 * ON for a guest who agreed later (logged). The CSV export contains
 * opted-in contacts ONLY.
 */
class GuestContactResource extends Resource
{
    protected static ?string $model = GuestContact::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static string|UnitEnum|null $navigationGroup = 'Guest Ordering';

    protected static ?string $navigationLabel = 'Guest Contacts';

    protected static ?int $navigationSort = 5;

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
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['stay.guest', 'stay.room', 'recordedBy']))
            ->columns([
                TextColumn::make('phone')->searchable()->copyable(),
                TextColumn::make('stay.guest.name')->label('Guest'),
                TextColumn::make('room')->label('Room')->state(fn (GuestContact $c) => $c->stay?->room ? 'Room '.$c->stay->room->number : null),
                IconColumn::make('marketing_opt_in')->label('Specials')->boolean(),
                TextColumn::make('recordedBy.name')->label('Saved by'),
                TextColumn::make('created_at')->label('Saved')->dateTime(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('marketing_opt_in')->label('Agreed to specials'),
            ])
            ->recordActions([
                Action::make('optIn')
                    ->label('Agreed to specials')
                    ->icon('heroicon-o-check')
                    ->requiresConfirmation()
                    ->modalDescription('Only if the guest has said yes. This can\'t be turned off here.')
                    ->visible(fn (GuestContact $record) => ! $record->marketing_opt_in && auth()->user()?->can('update', $record))
                    ->action(function (GuestContact $record) {
                        $record->optIn(auth()->user());
                        UserFeedback::succeeded('Specials turned on for '.$record->phone);
                    }),
            ])
            ->toolbarActions([]);
    }

    /**
     * The marketing export: opted-in contacts only, never anyone else.
     *
     * @return list<array<int, string>>
     */
    public static function exportRows(): array
    {
        return GuestContact::with(['stay.guest'])
            ->where('marketing_opt_in', true)
            ->orderBy('created_at')
            ->get()
            ->map(fn (GuestContact $c) => [
                $c->phone,
                (string) $c->stay?->guest?->name,
                (string) $c->opted_in_at?->venueTime()->format('Y-m-d H:i'),
            ])
            ->values()
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGuestContacts::route('/'),
        ];
    }
}
