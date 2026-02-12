<?php

namespace App\Filament\Resources\Guests;

use App\Filament\Resources\Guests\Pages\CreateGuest;
use App\Filament\Resources\Guests\Pages\EditGuest;
use App\Filament\Resources\Guests\Pages\ListGuests;
use App\Filament\Resources\Guests\Schemas\GuestForm;
use App\Filament\Resources\Guests\Tables\GuestsTable;
use App\Models\Guest;
use BackedEnum;
use UnitEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Tables\Filters\Filter;

class GuestResource extends Resource
{
    protected static ?string $model = Guest::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-circle';

    protected static string|UnitEnum|null $navigationGroup = 'User Management';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
        ->schema([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->label('Full Name'),

            TextInput::make('email')
                ->email()
                ->maxLength(255)
                ->placeholder('optional@email.com'),

            TextInput::make('phone')
                ->tel()
                ->maxLength(255)
                ->required(),

            TextInput::make('id_document_type')
                ->label('ID Type')
                ->placeholder('e.g. Passport, NIN, Driver\'s License'),

            TextInput::make('id_document_number')
                ->label('ID Number'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
        ->columns([
            TextColumn::make('name')
                ->searchable()
                ->sortable()
                ->weight('bold'),

            TextColumn::make('phone')
                ->icon('heroicon-m-phone')
                ->searchable(),

            TextColumn::make('total_debt')
                ->label('Outstanding Debt')
                ->money('NGN')
                ->state(function (Guest $record) {
                    // Sum up all unpaid amounts
                    return $record->orders()
                        ->where('status', 'partial')
                        ->get() // Get the orders first
                        ->sum(fn ($order) => $order->total_amount - $order->amount_paid);
                })
                ->color(fn ($state) => $state > 0 ? 'danger' : 'gray') // Red if debt > 0
                ->weight(fn ($state) => $state > 0 ? 'bold' : 'normal'),

            TextColumn::make('email')
                ->icon('heroicon-m-envelope')
                ->toggleable(isToggledHiddenByDefault: true), // Hidden by default to save space

            TextColumn::make('created_at')
                ->dateTime()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
        ])
        ->filters([
            // 🔥 NEW: Filter to show ONLY people who owe money
            Filter::make('debtors')
                ->label('Show Debtors Only')
                ->query(function ($query) {
                    return $query->whereHas('orders', function ($q) {
                        $q->where('status', 'partial');
                    });
                })
                ->toggle(), // Uses a simple toggle switch
        ])
        ->recordActions([
            Action::make('edit')
                ->icon('heroicon-o-pencil')
                ->label('Edit'),
            Action::make('delete')
                ->icon('heroicon-o-trash')
                ->label('Delete')
                ->requiresConfirmation()
                ->color('danger'),
        ])
        ->paginated([10, 25, 50, 100])
        ->toolbarActions([
            BulkAction::make('delete')
                ->icon('heroicon-o-trash')
                ->label('Delete Selected')
                ->requiresConfirmation()
                ->color('danger'),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\OrdersRelationManager::class,
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                // SUMMARY CARD
                Section::make('Guest Profile')
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('name')
                                ->size('large')
                                ->weight('bold'),
                            
                            TextEntry::make('phone')
                                ->icon('heroicon-m-phone'),

                            // CALCULATED STATS
                            TextEntry::make('total_spent')
                                ->label('Lifetime Value')
                                ->money('NGN')
                                ->state(fn (Guest $record) => $record->orders()->sum('amount_paid'))
                                ->color('success'),

                            TextEntry::make('total_debt')
                                ->label('Total Debt')
                                ->money('NGN')
                                ->state(function (Guest $record) {
                                    // Sum of (Total - Paid) where status is partial
                                    return $record->orders()
                                        ->where('status', 'partial')
                                        ->get()
                                        ->sum(fn ($o) => $o->total_amount - $o->amount_paid);
                                })
                                ->color(fn ($state) => $state > 0 ? 'danger' : 'gray')
                                ->weight('bold')
                                ->size('large'),
                        ]),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGuests::route('/'),
            'create' => CreateGuest::route('/create'),
            'edit' => EditGuest::route('/{record}/edit'),
            'view' => Pages\ViewGuest::route('/{record}'),
        ];
    }
}
