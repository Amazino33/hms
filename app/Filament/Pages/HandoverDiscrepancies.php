<?php

namespace App\Filament\Pages;

use App\Models\HandoverDiscrepancy;
use App\Services\CountSessionService;
use App\Services\PermissionService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class HandoverDiscrepancies extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';
    protected static ?string $navigationLabel = 'Handover Discrepancies';
    protected static string|UnitEnum|null $navigationGroup = 'Inventory';
    protected static ?int $navigationSort = 17;
    protected string $view = 'filament.pages.handover-discrepancies';

    public static function getNavigationBadge(): ?string
    {
        $count = HandoverDiscrepancy::where('status', 'pending_investigation')
            ->where('updated_at', '<', now()->subDays(2))
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                HandoverDiscrepancy::query()
                    ->with(['item.session', 'item.product', 'item.ingredient', 'resolvedBy', 'staffDebt'])
                    ->latest()
            )
            ->columns([
                TextColumn::make('session_ref')
                    ->label('Session')
                    ->state(fn (HandoverDiscrepancy $record) => '#' . $record->item->count_session_id)
                    ->url(fn (HandoverDiscrepancy $record) => "/admin/count-session-detail?session_id={$record->item->count_session_id}"),
                TextColumn::make('direction')
                    ->label('Type')
                    ->badge()
                    ->color(fn (HandoverDiscrepancy $record) => $record->isOverage() ? 'warning' : 'gray')
                    ->formatStateUsing(fn (HandoverDiscrepancy $record) => $record->isOverage() ? 'Overage' : 'Shortage'),
                TextColumn::make('accountable')
                    ->label('Accountable')
                    ->state(fn (HandoverDiscrepancy $record) => $record->item->session->accountableUserId()
                        ? \App\Models\User::find($record->item->session->accountableUserId())?->name ?? '—'
                        : '—'),
                TextColumn::make('item_name')
                    ->label('Item')
                    ->state(fn (HandoverDiscrepancy $record) => $record->item->itemName()),
                TextColumn::make('shortfall_quantity')
                    ->label('Qty')
                    ->numeric(2),
                TextColumn::make('naira_value')
                    ->label('Value')
                    ->money('NGN'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (HandoverDiscrepancy $record) => match (true) {
                        $record->isAgingInvestigation() => 'danger',
                        $record->status === 'pending_resolution' => 'warning',
                        $record->status === 'pending_investigation' => 'gray',
                        $record->status === 'debited' => 'danger',
                        $record->status === 'written_off' => 'success',
                        $record->status === 'acknowledged' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (HandoverDiscrepancy $record) => match (true) {
                        $record->isAgingInvestigation() => 'Investigation (2+ days)',
                        $record->status === 'pending_resolution' => 'Pending resolution',
                        $record->status === 'pending_investigation' => 'Pending investigation',
                        $record->status === 'debited' => 'Debited',
                        $record->status === 'written_off' => 'Written off',
                        $record->status === 'acknowledged' => 'Acknowledged',
                        default => $record->status,
                    }),
                TextColumn::make('resolvedBy.name')->label('Resolved by')->default('—'),
                TextColumn::make('created_at')->label('Opened')->dateTime('d M Y H:i'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending_resolution' => 'Pending resolution',
                        'pending_investigation' => 'Pending investigation',
                        'debited' => 'Debited',
                        'written_off' => 'Written off',
                        'acknowledged' => 'Acknowledged',
                    ]),
                SelectFilter::make('direction')
                    ->options(['shortage' => 'Shortage', 'overage' => 'Overage']),
                SelectFilter::make('accountable_user')
                    ->label('Accountable')
                    ->options(fn () => \App\Models\User::whereHas('roles', fn ($q) => $q->whereIn('name', ['bartender', 'chef', 'storekeeper']))->pluck('name', 'id'))
                    ->query(function (Builder $query, array $data) {
                        if (! $data['value']) {
                            return;
                        }
                        // accountableUserId() is outgoing_user_id normally, or
                        // opened_by when there's no outgoing (a solo store
                        // count) — matching that here, not just outgoing_user_id,
                        // is what makes this filter find storekeeper rows at all.
                        $query->whereHas('item.session', fn ($q) => $q
                            ->where('outgoing_user_id', $data['value'])
                            ->orWhere(fn ($q2) => $q2->whereNull('outgoing_user_id')->where('opened_by', $data['value'])));
                    }),
            ])
            ->recordActions([
                Action::make('recount')
                    ->label('Recount')
                    ->color('info')
                    ->visible(fn (HandoverDiscrepancy $record) => $record->isShortage() && $record->isOpen())
                    ->form([
                        TextInput::make('new_quantity')->numeric()->required()->minValue(0)->label('Recounted quantity'),
                        TextInput::make('counter_pin')->label('Counter PIN')->password()->numeric()->length(4)->required(),
                        TextInput::make('witness_pin')->label('Witness PIN')->password()->numeric()->length(4)->required(),
                    ])
                    ->action(function (HandoverDiscrepancy $record, array $data) {
                        try {
                            app(CountSessionService::class)->recordVerificationRecount(
                                $record,
                                (float) $data['new_quantity'],
                                $data['counter_pin'],
                                $data['witness_pin'],
                                Auth::id(),
                                "handover_discrepancy:{$record->id}:recount",
                            );
                            Notification::make()->success()->title('Recount recorded')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Could not record recount')->body($e->getMessage())->send();
                        }
                    }),
                Action::make('debit')
                    ->label('Debit')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (HandoverDiscrepancy $record) => $record->isShortage() && $record->isOpen())
                    ->action(function (HandoverDiscrepancy $record) {
                        try {
                            app(CountSessionService::class)->debitDiscrepancy($record, Auth::id());
                            Notification::make()->success()->title('Debited to outgoing custodian')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Could not debit')->body($e->getMessage())->send();
                        }
                    }),
                Action::make('pend')
                    ->label('Pend investigation')
                    ->color('gray')
                    ->visible(fn (HandoverDiscrepancy $record) => $record->isOpen())
                    ->form([
                        Textarea::make('note')->label('Investigation note')->required(),
                    ])
                    ->action(function (HandoverDiscrepancy $record, array $data) {
                        try {
                            app(CountSessionService::class)->pendDiscrepancyInvestigation($record, $data['note'], Auth::id());
                            Notification::make()->success()->title('Marked pending investigation')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Could not pend')->body($e->getMessage())->send();
                        }
                    }),
                Action::make('writeOff')
                    ->label('Resolve without debit')
                    ->color('success')
                    ->visible(fn (HandoverDiscrepancy $record) => $record->isShortage() && $record->isOpen())
                    ->form([
                        Textarea::make('reason')->label('Written reason')->required(),
                    ])
                    ->action(function (HandoverDiscrepancy $record, array $data) {
                        try {
                            app(CountSessionService::class)->writeOffDiscrepancy($record, $data['reason'], Auth::id());
                            Notification::make()->success()->title('Resolved without a debit')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Could not resolve')->body($e->getMessage())->send();
                        }
                    }),
                Action::make('acknowledge')
                    ->label('Acknowledge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Nothing was lost — the extra stock has already been added to the count. This just closes the line out.')
                    ->visible(fn (HandoverDiscrepancy $record) => $record->isOverage() && $record->isOpen())
                    ->action(function (HandoverDiscrepancy $record) {
                        try {
                            app(CountSessionService::class)->acknowledgeOverage($record, Auth::id());
                            Notification::make()->success()->title('Overage acknowledged')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Could not acknowledge')->body($e->getMessage())->send();
                        }
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('bulkDebit')
                    ->label('Debit all remaining')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (\Illuminate\Support\Collection $records) {
                        $result = app(CountSessionService::class)->bulkDebitRemaining($records, Auth::id());
                        Notification::make()->success()->title("Debited {$result['debited']}, skipped {$result['failed']} already resolved")->send();
                    }),
                BulkAction::make('bulkWriteOff')
                    ->label('Write off all remaining')
                    ->color('success')
                    ->requiresConfirmation()
                    ->form([
                        Textarea::make('reason')->label('Written reason')->required(),
                    ])
                    ->action(function (\Illuminate\Support\Collection $records, array $data) {
                        $result = app(CountSessionService::class)->bulkWriteOffRemaining($records, $data['reason'], Auth::id());
                        Notification::make()->success()->title("Wrote off {$result['written_off']}, skipped {$result['failed']} already resolved")->send();
                    }),
            ])
            ->striped();
    }

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }
}
