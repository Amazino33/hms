<?php

namespace App\Filament\Ceo\Resources\AttendanceLogs;

use App\Filament\Ceo\Resources\AttendanceLogs\Pages\ManageAttendanceLogs;
use App\Models\DailyAttendance;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\DatePicker;
use Illuminate\Database\Eloquent\Builder;

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
                \Filament\Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->getStateUsing(function (DailyAttendance $record) {
                        if (!$record->user || !$record->user->shift_start_time) return 'No Shift Time';
                        $firstPunch = \Carbon\Carbon::parse($record->first_punch)->timezone(\App\Support\VenueTime::TIMEZONE);
                        $dateStr = $record->date instanceof \Carbon\Carbon ? $record->date->toDateString() : \Carbon\Carbon::parse($record->date)->toDateString();
                        $shiftStart = \Carbon\Carbon::parse($dateStr . ' ' . $record->user->shift_start_time, \App\Support\VenueTime::TIMEZONE);
                        return $firstPunch->greaterThan($shiftStart) ? 'Late' : 'On Time';
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Late' => 'danger',
                        'On Time' => 'success',
                        default => 'gray',
                    }),
                \Filament\Tables\Columns\TextColumn::make('biometric_id')
                    ->label('Machine ID')
                    ->searchable(),
                \Filament\Tables\Columns\TextColumn::make('first_punch')
                    ->label('First Punch (In)')
                    ->dateTime('h:i A')
                    ->timezone(\App\Support\VenueTime::TIMEZONE)
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('last_punch')
                    ->label('Last Punch (Out)')
                    ->dateTime('h:i A')
                    ->timezone(\App\Support\VenueTime::TIMEZONE)
                    ->sortable(),
            ])
            ->defaultSort('date', 'desc')
            ->filters([
                Filter::make('date')
                    ->form([
                        DatePicker::make('from')
                            ->label('From Date'),
                        DatePicker::make('until')
                            ->label('To Date'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('date', '>=', $date),
                            )
                            ->when(
                                $data['until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('date', '<=', $date),
                            );
                    })
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
