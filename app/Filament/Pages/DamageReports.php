<?php

namespace App\Filament\Pages;

use App\Models\DamageReport;
use App\Services\DamageReportService;
use App\Services\PermissionService;
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

/**
 * Manager approval queue for bartender/storekeeper-reported damages.
 * Deliberately a Page with InteractsWithTable (not a scaffolded Resource)
 * — matching HandoverDiscrepancies.php's own established pattern for this
 * exact shape of thing: an append-only queue resolved by Approve/Reject
 * actions, never edited or deleted.
 */
class DamageReports extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';
    protected static ?string $navigationLabel = 'Damage Reports';
    protected static string|UnitEnum|null $navigationGroup = 'Inventory';
    protected static ?int $navigationSort = 18;
    protected string $view = 'filament.pages.damage-reports';

    public static function getNavigationBadge(): ?string
    {
        $count = DamageReport::where('status', 'pending')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                DamageReport::query()
                    ->with(['product', 'ingredient', 'warehouse', 'reportedBy', 'resolvedBy'])
                    ->latest()
            )
            ->columns([
                TextColumn::make('warehouse.name')->label('Location'),
                TextColumn::make('item')
                    ->label('Item')
                    ->state(fn (DamageReport $record) => $record->itemName()),
                TextColumn::make('quantity')->numeric(2),
                TextColumn::make('note')->limit(40),
                TextColumn::make('reportedBy.name')->label('Reported by')->default('—'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (DamageReport $record) => match ($record->status) {
                        'pending' => 'warning',
                        'approved' => 'danger',
                        'rejected' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('resolvedBy.name')->label('Resolved by')->default('—'),
                TextColumn::make('created_at')->label('Reported')->dateTime('d M Y H:i'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected']),
                SelectFilter::make('warehouse_id')
                    ->label('Location')
                    ->options(fn () => \App\Models\WareHouse::pluck('name', 'id')),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('This writes the stock off at cost. This cannot be undone — a mistaken approval needs a compensating record, not an edit.')
                    ->visible(fn (DamageReport $record) => $record->isPending())
                    ->form([
                        Textarea::make('resolution_note')->label('Note (optional)'),
                    ])
                    ->action(function (DamageReport $record, array $data) {
                        try {
                            app(DamageReportService::class)->approve($record, Auth::id(), $data['resolution_note'] ?? null);
                            Notification::make()->success()->title('Damage approved — stock written off')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Could not approve')->body($e->getMessage())->persistent()->send();
                        }
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->color('gray')
                    ->visible(fn (DamageReport $record) => $record->isPending())
                    ->form([
                        Textarea::make('resolution_note')->label('Reason')->required(),
                    ])
                    ->action(function (DamageReport $record, array $data) {
                        try {
                            app(DamageReportService::class)->reject($record, Auth::id(), $data['resolution_note']);
                            Notification::make()->success()->title('Damage report rejected')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Could not reject')->body($e->getMessage())->persistent()->send();
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
