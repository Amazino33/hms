<?php

namespace App\Filament\Resources\AttendanceLogs;

use App\Filament\Concerns\ExportsDailyAttendance;
use App\Filament\Resources\AttendanceLogs\Pages\ManageAttendanceLogs;
use App\Models\DailyAttendance;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AttendanceLogResource extends Resource
{
    use ExportsDailyAttendance;

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
                // The name typed into the terminal at enrolment. Shown
                // alongside Staff Member rather than instead of it: this is
                // the device's record, and for a badge nobody has paired to a
                // profile yet it is the only name there is.
                \Filament\Tables\Columns\TextColumn::make('enrollment.name')
                    ->label('Name on Machine')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->getStateUsing(fn (DailyAttendance $record): string => $record->status())
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
                    }),
            ])
            ->recordActions([])
            ->toolbarActions([
                static::exportAction(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAttendanceLogs::route('/'),
        ];
    }
}
