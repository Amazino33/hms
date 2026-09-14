<?php

namespace App\Filament\Resources\AttendanceLogs;

use App\Filament\Resources\AttendanceLogs\Pages\ManageAttendanceLogs;
use App\Models\DailyAttendance;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class AttendanceLogResource extends Resource
{
    protected static ?string $model = DailyAttendance::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'Daily Attendance';
    protected static ?string $pluralModelLabel = 'Daily Attendance Records';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('date')
                    ->label('Date')
                    ->date()
                    ->sortable()
                    ->searchable(),
                \Filament\Tables\Columns\TextColumn::make('user.name')
                    ->label('Staff Member')
                    ->searchable()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('biometric_id')
                    ->label('Machine ID')
                    ->searchable(),
                \Filament\Tables\Columns\TextColumn::make('first_punch')
                    ->label('First Punch (In)')
                    ->dateTime('h:i A')
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('last_punch')
                    ->label('Last Punch (Out)')
                    ->dateTime('h:i A')
                    ->sortable(),
            ])
            ->defaultSort('date', 'desc')
            ->filters([
                //
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAttendanceLogs::route('/'),
        ];
    }
}
