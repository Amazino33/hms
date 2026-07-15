<?php

namespace App\Filament\Pages;

use App\Models\OrderPayment;
use App\Services\OrderPaymentVerificationService;
use App\Services\PermissionService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/**
 * The cashier's home screen and highest-volume task — most payments at
 * this venue are electronic, so this is deliberately the fastest,
 * plainest surface in her panel: single-click verify, bulk-select for a
 * run of consecutive confirmed items, a short reason required only for
 * the flag path. payer_reference shows when present but nothing here
 * adds a way to CAPTURE one at POS checkout — that's order-taking, out
 * of scope.
 */
class TransferQueue extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path';

    protected static string|UnitEnum|null $navigationGroup = 'Cashier';

    protected static ?string $navigationLabel = 'Transfer Queue';

    protected static ?string $title = 'Transfer Verification Queue';

    protected static ?int $navigationSort = -10;

    protected string $view = 'filament.pages.transfer-queue';

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                OrderPayment::query()
                    ->where('method', 'transfer')
                    ->where('verified', false)
                    ->whereNull('ruling')
                    ->with(['order.table', 'user'])
            )
            ->defaultSort('paid_at')
            ->columns([
                TextColumn::make('paid_at')->label('Time')->dateTime('g:i A')->sortable(),
                TextColumn::make('amount')->label('Amount')->money('NGN')->sortable(),
                TextColumn::make('user.name')->label('Staff'),
                TextColumn::make('order.order_number')->label('Order'),
                TextColumn::make('payer_reference')->label('Payer Ref')->placeholder('—'),
            ])
            ->recordActions([
                Action::make('verify')
                    ->label('Verify')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->action(function (OrderPayment $record) {
                        try {
                            (new OrderPaymentVerificationService())->verify($record, auth()->id());
                            Notification::make()->title('Verified')->success()->send();
                        } catch (\Exception $e) {
                            Notification::make()->title('Could not verify')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('flag')
                    ->label('Flag')
                    ->icon('heroicon-o-flag')
                    ->color('danger')
                    ->form([
                        Select::make('reason_code')
                            ->label('Reason')
                            ->options([
                                'not_found' => 'Not found',
                                'amount_mismatch' => 'Amount mismatch',
                                'duplicate' => 'Duplicate',
                            ])
                            ->required(),
                        Textarea::make('note')->label('Note')->required(),
                    ])
                    ->action(function (OrderPayment $record, array $data) {
                        try {
                            (new OrderPaymentVerificationService())->flag($record, $data['note'], $data['reason_code'], auth()->id());
                            Notification::make()->title('Flagged for supervisor')->warning()->send();
                        } catch (\Exception $e) {
                            Notification::make()->title('Could not flag')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('bulkVerify')
                    ->label('Verify selected')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->action(function (Collection $records) {
                        $service = new OrderPaymentVerificationService();
                        $count = 0;

                        foreach ($records as $record) {
                            try {
                                $service->verify($record, auth()->id());
                                $count++;
                            } catch (\Exception $e) {
                                // Skip anything already resolved out from
                                // under this bulk action rather than
                                // aborting the whole batch.
                            }
                        }

                        Notification::make()->title("{$count} verified")->success()->send();
                    }),
            ]);
    }
}
