<?php

namespace App\Filament\Pages;

use App\Models\Shift;
use App\Services\PermissionService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Pages\Page;
use UnitEnum;

/**
 * A cashier has no access to the admin ShiftManagement resource (out of
 * her narrow permission scope), so this is her own list of settlements
 * awaiting confirmation — links to the same shared SettlementDetail
 * screen a supervisor uses for fallback.
 */
class CashierSettlementsQueue extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string|UnitEnum|null $navigationGroup = 'Cashier';

    protected static ?string $navigationLabel = 'Settlements';

    protected static ?string $title = 'Settlements Awaiting Confirmation';

    protected string $view = 'filament.pages.cashier-settlements-queue';

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Shift::query()->where('status', 'awaiting_cashier')->with('user'))
            ->defaultSort('ended_at')
            ->columns([
                TextColumn::make('user.name')->label('Staff'),
                TextColumn::make('type')->badge()->formatStateUsing(fn (string $s) => ucfirst($s)),
                TextColumn::make('ended_at')->label('Ended')->dateTime('M j, g:ia')->sortable(),
                TextColumn::make('declared_cash')->label('Declared Cash')->money('NGN'),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Confirm')
                    ->icon('heroicon-o-check-badge')
                    ->url(fn (Shift $record): string => '/admin/settlement?shift=' . $record->id),
            ]);
    }
}
