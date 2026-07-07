<?php

namespace App\Filament\Resources\KioskDevices;

use App\Filament\Resources\KioskDevices\Pages\ListKioskDevices;
use App\Models\KioskDevice;
use App\Models\User;
use App\Services\KioskDeviceService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class KioskDeviceResource extends Resource
{
    protected static ?string $model = KioskDevice::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-device-tablet';
    protected static string|UnitEnum|null $navigationGroup = 'Administration';
    protected static ?string $navigationLabel = 'Registered Kiosks';
    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            TextInput::make('name')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('registeredBy.name')->label('Registered By'),
                TextColumn::make('registered_at')->dateTime('M j, Y g:i A'),
                TextColumn::make('last_seen_at')
                    ->label('Last Seen')
                    ->formatStateUsing(fn (?string $state) => $state ? \Carbon\Carbon::parse($state)->format('M j, Y g:i A') : 'Never'),
                TextColumn::make('status')
                    ->state(fn (KioskDevice $record) => $record->isRevoked() ? 'Revoked' : 'Active')
                    ->badge()
                    ->color(fn (KioskDevice $record) => $record->isRevoked() ? 'danger' : 'success'),
            ])
            ->recordActions([
                Action::make('rename')
                    ->label('Rename')
                    ->icon('heroicon-o-pencil')
                    ->form([
                        TextInput::make('name')->required(),
                    ])
                    ->fillForm(fn (KioskDevice $record) => ['name' => $record->name])
                    ->action(function (KioskDevice $record, array $data) {
                        (new KioskDeviceService())->rename($record, $data['name']);
                        Notification::make()->title('Device renamed')->success()->send();
                    }),

                Action::make('revoke')
                    ->label('Revoke')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (KioskDevice $record) => !$record->isRevoked())
                    ->action(function (KioskDevice $record) {
                        (new KioskDeviceService())->revoke($record, auth()->user());
                        Notification::make()->title('Device revoked')->body('This device can no longer reach any kiosk route.')->success()->send();
                    }),
            ])
            ->headerActions([
                Action::make('generateCode')
                    ->label('Generate Registration Code')
                    ->icon('heroicon-o-key')
                    ->action(function () {
                        ['code' => $code] = (new KioskDeviceService())->generateRegistrationCode(auth()->user());

                        Notification::make()
                            ->title('Registration code generated')
                            ->body("Code: {$code} — valid for 15 minutes, single use. Enter this on the physical device's registration screen now.")
                            ->success()
                            ->persistent()
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKioskDevices::route('/'),
        ];
    }
}
