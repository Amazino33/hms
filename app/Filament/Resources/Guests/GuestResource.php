<?php

namespace App\Filament\Resources\Guests;

use App\Filament\Resources\Guests\Pages\CreateGuest;
use App\Filament\Resources\Guests\Pages\EditGuest;
use App\Filament\Resources\Guests\Pages\ListGuests;
use App\Filament\Resources\Guests\Schemas\GuestForm;
use App\Filament\Resources\Guests\Tables\GuestsTable;
use App\Models\Guest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;

class GuestResource extends Resource
{
    protected static ?string $model = Guest::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-circle';

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

            TextColumn::make('email')
                ->icon('heroicon-m-envelope')
                ->toggleable(isToggledHiddenByDefault: true), // Hidden by default to save space

            TextColumn::make('created_at')
                ->dateTime()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGuests::route('/'),
            'create' => CreateGuest::route('/create'),
            'edit' => EditGuest::route('/{record}/edit'),
        ];
    }
}
