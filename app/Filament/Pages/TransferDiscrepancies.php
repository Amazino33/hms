<?php

namespace App\Filament\Pages;

use App\Models\TransferDiscrepancy;
use App\Services\PermissionService;
use App\Services\StockTransferService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class TransferDiscrepancies extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';
    protected static ?string $navigationLabel = 'Transfer Discrepancies';
    protected static string|UnitEnum|null $navigationGroup = 'Inventory';
    protected static ?int $navigationSort = 16;
    protected string $view = 'filament.pages.transfer-discrepancies';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                TransferDiscrepancy::query()
                    ->with([
                        'stockTransferItem.transfer', 'stockTransferItem.product',
                        'ingredientTransferItem.transfer', 'ingredientTransferItem.ingredient',
                        'resolvedBy',
                    ])
                    ->latest()
            )
            ->columns([
                TextColumn::make('transfer_number')
                    ->label('Transfer')
                    ->state(fn (TransferDiscrepancy $record) => ($record->stockTransferItem ?? $record->ingredientTransferItem)?->transfer?->transfer_number ?? '—'),
                TextColumn::make('item_name')
                    ->label('Item')
                    ->state(fn (TransferDiscrepancy $record) => $record->itemName()),
                TextColumn::make('missing_base_qty')
                    ->label('Missing qty')
                    ->numeric(2),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'open' => 'warning',
                        'reversed_to_store' => 'success',
                        'written_off_missing' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('resolvedBy.name')
                    ->label('Resolved by')
                    ->default('—'),
                TextColumn::make('created_at')
                    ->label('Opened')
                    ->dateTime('d M Y H:i'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'open' => 'Open',
                        'reversed_to_store' => 'Reversed to store',
                        'written_off_missing' => 'Written off',
                    ])
                    ->default('open'),
            ])
            ->recordActions([
                Action::make('reverse')
                    ->label('Reverse to store')
                    ->color('success')
                    ->visible(fn (TransferDiscrepancy $record) => $record->isOpen())
                    ->requiresConfirmation()
                    ->form([
                        Textarea::make('note')->label('Resolution note')->required(),
                    ])
                    ->action(function (TransferDiscrepancy $record, array $data) {
                        try {
                            app(StockTransferService::class)->reverseDiscrepancyToStore($record, Auth::id(), $data['note']);
                            Notification::make()->success()->title('Reversed to main store')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Could not reverse')->body($e->getMessage())->persistent()->send();
                        }
                    }),
                Action::make('writeOff')
                    ->label('Write off as missing')
                    ->color('danger')
                    ->visible(fn (TransferDiscrepancy $record) => $record->isOpen())
                    ->requiresConfirmation()
                    ->form([
                        Textarea::make('note')->label('Resolution note')->required(),
                    ])
                    ->action(function (TransferDiscrepancy $record, array $data) {
                        try {
                            app(StockTransferService::class)->writeOffDiscrepancy($record, Auth::id(), $data['note']);
                            Notification::make()->success()->title('Written off as missing')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Could not write off')->body($e->getMessage())->persistent()->send();
                        }
                    }),
            ])
            ->striped();
    }

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }
}
