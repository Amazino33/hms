<?php

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Staff who are not exempt and have no schedule today.
 *
 * These are the people attendance silently skips: no schedule means no
 * expected shift, which means they can never be marked absent and never be
 * fined. That is invisible by nature — nothing goes wrong, nothing appears in
 * a report — so it needs a list of its own or it is never noticed.
 */
class StaffWithoutScheduleWidget extends BaseWidget
{
    protected static ?string $heading = 'Staff with no schedule';

    protected static ?int $sort = 50;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('ViewAny:AttendanceShiftTemplate') ?? false;
    }

    public function table(Table $table): Table
    {
        $today = now()->timezone(\App\Support\VenueTime::TIMEZONE)->toDateString();

        return $table
            ->query(
                User::query()
                    ->whereNull('left_at')
                    ->where('attendance_exempt', false)
                    ->whereDoesntHave('attendanceShiftAssignments', function (Builder $query) use ($today) {
                        $query->whereDate('effective_from', '<=', $today)
                            ->where(function (Builder $q) use ($today) {
                                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today);
                            });
                    })
                    ->orderBy('name')
            )
            ->columns([
                TextColumn::make('name')->label('Staff member')->searchable(),
                TextColumn::make('job_title')->label('Job title')->placeholder('—'),
                TextColumn::make('roles.name')->label('Roles')->badge()->placeholder('—'),
                TextColumn::make('biometric_id')->label('Device ID')->placeholder('Not linked'),
            ])
            ->emptyStateHeading('Everyone has a schedule')
            ->emptyStateDescription('Every non-exempt staff member is on a shift pattern.')
            ->recordUrl(fn (User $record) => \App\Filament\Resources\Users\UserResource::getUrl('edit', ['record' => $record]))
            ->paginated([10, 25, 50]);
    }
}
