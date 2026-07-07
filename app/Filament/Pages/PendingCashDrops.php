<?php

namespace App\Filament\Pages;

use App\Models\CashDrop;
use App\Services\CashDropService;
use App\Services\PermissionService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

/**
 * A manager only ever sees (and can act on) drops declared to THEM
 * specifically — not every drop in the system.
 */
class PendingCashDrops extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';
    protected static string|UnitEnum|null $navigationGroup = 'Restaurant Management';
    protected static ?string $navigationLabel = 'Cash Drops';
    protected static ?string $title = 'Cash Drops';
    protected string $view = 'filament.pages.pending-cash-drops';

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(CashDrop::query()->where('received_by', auth()->id())->with(['waiter', 'shift']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('waiter.name')->label('From'),
                TextColumn::make('declared_amount')->label('Declared')->money('NGN'),
                TextColumn::make('confirmed_amount')->label('Confirmed')->money('NGN')->default('—'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'confirmed' ? 'success' : 'warning'),
                TextColumn::make('note')->limit(40),
                TextColumn::make('created_at')->label('Declared At')->dateTime('M j, Y g:i A'),
            ])
            ->recordActions([
                Action::make('confirm')
                    ->label('Confirm')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (CashDrop $record) => $record->isPending())
                    ->action(function (CashDrop $record) {
                        try {
                            (new CashDropService())->confirm($record, auth()->user());
                            Notification::make()->title('Drop confirmed')->success()->send();
                        } catch (\Exception $e) {
                            Notification::make()->title('Could not confirm')->body($e->getMessage())->danger()->send();
                        }
                    }),

                Action::make('correctAndConfirm')
                    ->label('Enter Actual Amount')
                    ->icon('heroicon-o-pencil')
                    ->color('warning')
                    ->visible(fn (CashDrop $record) => $record->isPending())
                    ->form([
                        TextInput::make('actual_amount')->numeric()->required()->minValue(0.01)->label('Amount Actually Received'),
                    ])
                    ->action(function (CashDrop $record, array $data) {
                        try {
                            (new CashDropService())->confirm($record, auth()->user(), (float) $data['actual_amount']);
                            Notification::make()->title('Drop confirmed with corrected amount')->success()->send();
                        } catch (\Exception $e) {
                            Notification::make()->title('Could not confirm')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }
}
