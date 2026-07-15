<?php

namespace App\Filament\Pages;

use App\Models\CountSession;
use App\Services\PermissionService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class MyHandoverHistory extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';
    protected static string|UnitEnum|null $navigationGroup = 'Inventory';
    protected static ?string $navigationLabel = 'My Handover History';
    protected static ?string $title = 'My Handover History';
    protected string $view = 'filament.pages.my-handover-history';

    public function table(Table $table): Table
    {
        $userId = Auth::id();

        return $table
            ->query(
                CountSession::query()
                    ->where(fn ($q) => $q->where('outgoing_user_id', $userId)
                        ->orWhere('incoming_user_id', $userId)
                        // A solo count (store stocktake) never sets outgoing/
                        // incoming — opened_by is the only identity it has.
                        ->orWhere(fn ($q2) => $q2->whereNull('outgoing_user_id')->where('opened_by', $userId)))
                    ->whereIn('status', ['reviewed', 'declared', 'counting'])
                    ->with(['warehouse', 'outgoingUser', 'incomingUser'])
            )
            ->defaultSort('opened_at', 'desc')
            ->columns([
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'bar_handover' => 'Bar Handover',
                        'kitchen_handover' => 'Kitchen Handover',
                        'main_store_stocktake' => 'Store Count',
                        default => $state,
                    }),
                TextColumn::make('warehouse.name')->label('Warehouse'),
                TextColumn::make('role')
                    ->label('My Role')
                    ->state(fn (CountSession $record) => match (true) {
                        $record->outgoing_user_id === $userId => 'Outgoing',
                        $record->incoming_user_id === $userId => 'Incoming',
                        default => 'Counter',
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ucwords(str_replace('_', ' ', $state))),
                TextColumn::make('total_shortage_value')
                    ->label('Shortage ₦')
                    ->money('NGN')
                    ->placeholder('—'),
                TextColumn::make('opened_at')->dateTime('M j, Y g:i A'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(['counting' => 'Counting', 'declared' => 'Declared', 'reviewed' => 'Reviewed']),
            ])
            ->recordUrl(fn (CountSession $record) => "/admin/count-session-detail?session_id={$record->id}");
    }

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }
}
