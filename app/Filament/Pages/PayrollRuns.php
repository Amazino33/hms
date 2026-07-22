<?php

namespace App\Filament\Pages;

use App\Models\PayrollRun;
use App\Services\PayrollCompilationService;
use App\Services\PermissionService;
use App\Services\SettingsService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class PayrollRuns extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|UnitEnum|null $navigationGroup = 'System Management';

    protected static ?string $navigationLabel = 'Payroll';

    protected static ?string $title = 'Payroll Runs';

    protected string $view = 'filament.pages.payroll-runs';

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(PayrollRun::query()->withCount('lines')->with('preparer'))
            ->defaultSort('period_start', 'desc')
            ->columns([
                TextColumn::make('period_start')->label('Period')->date('M j')
                    ->formatStateUsing(fn (PayrollRun $record) => $record->period_start->format('M j').' – '.$record->period_end->format('M j, Y')),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'draft' => 'gray',
                        'sealed' => 'warning',
                        'closed' => 'success',
                        'voided' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('lines_count')->label('Staff'),
                TextColumn::make('preparer.name')->label('Prepared By'),
                TextColumn::make('sealed_at')->dateTime('M j, Y g:i A')->placeholder('—'),
                TextColumn::make('created_at')->label('Created')->dateTime('M j, Y g:i A'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'sealed' => 'Sealed',
                        'closed' => 'Closed',
                        'voided' => 'Voided',
                    ]),
            ])
            ->recordUrl(fn (PayrollRun $record) => "/admin/payroll-run-detail?run_id={$record->id}")
            ->headerActions([
                Action::make('newRun')
                    ->label('New Payroll Run')
                    ->form([
                        DatePicker::make('period_start')
                            ->label('Period Start')
                            ->default(fn () => CarbonImmutable::now()->startOfMonth()->toDateString())
                            ->required(),
                        DatePicker::make('period_end')
                            ->label('Period End')
                            ->default(fn () => CarbonImmutable::now()->endOfMonth()->toDateString())
                            ->required(),
                        DatePicker::make('payday')
                            ->label('Expected Payday (optional)')
                            ->default(function () {
                                $day = (int) SettingsService::get('payroll_payday_day', '');

                                return $day > 0 ? CarbonImmutable::now()->startOfMonth()->addDays($day - 1)->toDateString() : null;
                            }),
                    ])
                    ->action(function (array $data, Action $action) {
                        try {
                            $run = (new PayrollCompilationService())->draftRun(
                                CarbonImmutable::parse($data['period_start']),
                                CarbonImmutable::parse($data['period_end']),
                                ! empty($data['payday']) ? CarbonImmutable::parse($data['payday']) : null,
                                auth()->user(),
                            );

                            Notification::make()->title('Payroll run drafted')->success()->send();

                            $action->redirect("/admin/payroll-run-detail?run_id={$run->id}");
                        } catch (\Exception $e) {
                            Notification::make()->title('Could not draft payroll run')->body($e->getMessage())->danger()->persistent()->send();
                        }
                    }),
            ]);
    }
}
