<?php

namespace App\Filament\Pages;

use App\Models\Attendance\AttendanceSetting;
use App\Support\VenueTime;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The fine amounts and thresholds, and the history of what they have been.
 *
 * Saving writes a NEW version rather than editing the live one. A fine raised
 * in March has to stay explainable in June using March's figures, and an
 * in-place edit would silently rewrite the basis of every penalty already
 * handed out under it.
 *
 * Super admin only — these numbers decide what comes out of people's pay.
 */
class AttendanceSettingsPage extends Page implements HasSchemas, HasTable
{
    use InteractsWithSchemas;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?string $navigationLabel = 'Attendance Rules';

    protected static ?string $title = 'Attendance Rules';

    protected static ?string $slug = 'attendance-rules';

    protected string $view = 'filament.pages.attendance-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        // Not PagePermission-gated: there is no case for delegating the fine
        // amounts, so the check is the blunt one rather than a configurable
        // one somebody could widen by accident.
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public function mount(): void
    {
        $current = AttendanceSetting::current();

        $this->form->fill([
            'effective_from' => now()->timezone(VenueTime::TIMEZONE)->toDateString(),
            'grace_minutes' => $current?->grace_minutes ?? 15,
            'duplicate_punch_window_minutes' => $current?->duplicate_punch_window_minutes ?? 30,
            'fine_late' => $current?->fine_late ?? 500,
            'fine_late_relief' => $current?->fine_late_relief ?? 1000,
            'fine_early_leave' => $current?->fine_early_leave ?? 1500,
            'fine_no_clockout' => $current?->fine_no_clockout ?? 1500,
            'fine_absent' => $current?->fine_absent ?? 3000,
            'absence_day_pay_amount' => $current?->absence_day_pay_amount,
            'rules_start_date' => $current?->rules_start_date?->toDateString(),
            'shadow_mode' => $current?->shadow_mode ?? true,
            'window_before_minutes' => $current?->window_before_minutes ?? 120,
            'window_after_minutes' => $current?->window_after_minutes ?? 240,
            'finalise_delay_minutes' => $current?->finalise_delay_minutes ?? 60,
            'max_device_wait_minutes' => $current?->max_device_wait_minutes ?? 1440,
            'early_leave_grace_minutes' => $current?->early_leave_grace_minutes ?? 0,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('When these rules apply')
                    ->description('Saving creates a new version. Earlier versions are kept exactly as they were, so past fines stay explainable.')
                    ->schema([
                        DatePicker::make('effective_from')
                            ->label('New version takes effect')
                            ->required()
                            ->default(now()),
                        Toggle::make('shadow_mode')
                            ->label('Shadow mode')
                            ->helperText('On: everything is worked out and nothing is charged. Leave this on until the figures look right.'),
                        DatePicker::make('rules_start_date')
                            ->label('Rules announced from')
                            ->minDate(fn () => now()->timezone(VenueTime::TIMEZONE)->addDay()->startOfDay())
                            ->rules([
                                // Server-side as well as in the picker: a date
                                // in the past would charge people for days
                                // they were never told about, and a disabled
                                // input is a convenience, not a guarantee.
                                fn (): \Closure => function (string $attribute, $value, \Closure $fail) {
                                    if (blank($value)) {
                                        return;
                                    }

                                    $tomorrow = CarbonImmutable::now(VenueTime::TIMEZONE)->addDay()->startOfDay();

                                    if (CarbonImmutable::parse($value, VenueTime::TIMEZONE)->startOfDay()->lessThan($tomorrow)) {
                                        $fail('Rules can only start from tomorrow onward — staff cannot be fined for days they were never told about.');
                                    }
                                },
                            ])
                            ->helperText('Tomorrow at the earliest. Nothing before this date is ever fined, however the rules read.'),
                    ])->columns(3),

                Section::make('Thresholds')
                    ->schema([
                        TextInput::make('grace_minutes')
                            ->label('Grace period (minutes)')
                            ->numeric()->minValue(0)->maxValue(120)->required()
                            ->helperText('Arriving within this many minutes of the scheduled start is on time.'),
                        TextInput::make('early_leave_grace_minutes')
                            ->label('Early-leave grace (minutes)')
                            ->numeric()->minValue(0)->maxValue(120)->required()
                            ->helperText('At 0, clocking out one minute early is a full fine. On handover shifts the relief is already there, so a few minutes either side of the hour is normal.'),
                        TextInput::make('duplicate_punch_window_minutes')
                            ->label('Duplicate punch window (minutes)')
                            ->numeric()->minValue(0)->maxValue(240)->required()
                            ->helperText('Two punches closer together than this count as one — people often touch the reader twice.'),
                    ])->columns(3),

                Section::make('Shift windows and timing')
                    ->description('How far either side of a shift a punch still counts, and how long to wait before judging it.')
                    ->schema([
                        TextInput::make('window_before_minutes')
                            ->label('Window before start (minutes)')
                            ->numeric()->minValue(0)->maxValue(720)->required()
                            ->helperText('People arrive early; a punch this far ahead is still a clock-in.'),
                        TextInput::make('window_after_minutes')
                            ->label('Window after end (minutes)')
                            ->numeric()->minValue(0)->maxValue(720)->required()
                            ->helperText('Be generous: a punch outside every window counts as nothing, which turns a late clock-out into an absence.'),
                        TextInput::make('finalise_delay_minutes')
                            ->label('Wait before judging (minutes)')
                            ->numeric()->minValue(0)->maxValue(720)->required()
                            ->helperText('Breathing room so a push still in flight is not read as absence.'),
                        TextInput::make('max_device_wait_minutes')
                            ->label('Maximum wait for a silent device (minutes)')
                            ->numeric()->minValue(0)->maxValue(10080)->required()
                            ->helperText('A terminal that goes offline buffers its punches. Judging before they arrive marks everybody absent for a day they all worked.'),
                    ])->columns(2),

                Section::make('Fines (₦)')
                    ->schema([
                        TextInput::make('fine_late')->label('Late')->numeric()->minValue(0)->required(),
                        TextInput::make('fine_late_relief')
                            ->label('Late on a handover shift')
                            ->numeric()->minValue(0)->required()
                            ->helperText('Replaces the late fine, never added to it.'),
                        TextInput::make('fine_early_leave')->label('Left before shift end')->numeric()->minValue(0)->required(),
                        TextInput::make('fine_no_clockout')->label('No clock-out')->numeric()->minValue(0)->required(),
                        TextInput::make('fine_absent')->label('Absent')->numeric()->minValue(0)->required(),
                        TextInput::make('absence_day_pay_amount')
                            ->label('Day pay deducted when absent')
                            ->numeric()->minValue(0)
                            ->helperText('On top of the absence fine. Leave blank until you have decided the figure.'),
                    ])->columns(3),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        AttendanceSetting::create($data + ['created_by' => auth()->id()]);

        Notification::make()
            ->success()
            ->title('New rules version saved')
            ->body('Earlier versions are untouched — fines already raised keep the figures they were raised under.')
            ->send();

        $this->resetTable();
    }

    /**
     * The version history, newest first. Read-only by construction: there is
     * no edit or delete action here and the model is append-only.
     */
    public function table(Table $table): Table
    {
        return $table
            ->query(AttendanceSetting::query())
            ->columns([
                TextColumn::make('effective_from')->label('In effect from')->date()->sortable(),
                IconColumn::make('shadow_mode')->label('Shadow')->boolean(),
                TextColumn::make('rules_start_date')->label('Announced from')->date()->placeholder('—'),
                TextColumn::make('grace_minutes')->label('Grace')->suffix(' min'),
                TextColumn::make('fine_late')->label('Late')->money('ngn'),
                TextColumn::make('fine_late_relief')->label('Relief')->money('ngn'),
                TextColumn::make('fine_early_leave')->label('Early leave')->money('ngn'),
                TextColumn::make('fine_no_clockout')->label('No clock-out')->money('ngn'),
                TextColumn::make('fine_absent')->label('Absent')->money('ngn'),
                TextColumn::make('creator.name')->label('Saved by')->placeholder('—'),
                TextColumn::make('created_at')->label('Saved')->dateTime(),
            ])
            ->defaultSort('effective_from', 'desc')
            ->recordActions([])
            ->toolbarActions([]);
    }
}
