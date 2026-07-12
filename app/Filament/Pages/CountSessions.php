<?php

namespace App\Filament\Pages;

use App\Models\CountSession;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\CountSessionService;
use App\Services\PermissionService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CountSessions extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static string|UnitEnum|null $navigationGroup = 'Inventory';
    protected static ?string $navigationLabel = 'Count Sessions';
    protected static ?string $title = 'Count Sessions';
    protected string $view = 'filament.pages.count-sessions';

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(CountSession::query()->with(['warehouse', 'openedBy', 'outgoingUser', 'incomingUser', 'items']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'bar_handover' => 'Bar Handover',
                        'kitchen_handover' => 'Kitchen Handover',
                        'main_store_stocktake' => 'Main Store Stocktake',
                        default => $state,
                    }),
                TextColumn::make('warehouse.name')->label('Warehouse'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'counting' => 'Counting',
                        'declared' => 'Declared',
                        'pending_review' => 'Pending Review',
                        'reviewed' => 'Reviewed',
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        'counting' => 'warning',
                        'declared' => 'info',
                        'pending_review' => 'info',
                        'reviewed' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('openedBy.name')->label('Opened By'),
                TextColumn::make('outgoingUser.name')->label('Outgoing')->default('—'),
                TextColumn::make('incomingUser.name')->label('Incoming')->default('—'),
                TextColumn::make('shortfall')
                    ->label('Shortfall?')
                    ->state(fn (CountSession $record) => $record->status !== 'reviewed'
                        ? null
                        : ($record->items->contains('decision', 'accountability') ? 'Yes' : 'No'))
                    ->badge()
                    ->color(fn (?string $state) => $state === 'Yes' ? 'danger' : 'success')
                    ->placeholder('—'),
                TextColumn::make('total_shortage_value')
                    ->label('Shortage ₦')
                    ->money('NGN')
                    ->placeholder('—'),
                TextColumn::make('unresolved')
                    ->label('Unresolved')
                    ->state(fn (CountSession $record) => $record->unresolvedDiscrepancyCount())
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'warning' : 'success'),
                TextColumn::make('unwitnessed')
                    ->label('Unwitnessed')
                    ->state(fn (CountSession $record) => $record->isUnwitnessed() ? 'Yes' : '—')
                    ->color(fn (string $state) => $state === 'Yes' ? 'warning' : 'gray'),
                TextColumn::make('opened_at')->dateTime('M j, Y g:i A'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(['counting' => 'Counting', 'declared' => 'Declared', 'pending_review' => 'Pending Review', 'reviewed' => 'Reviewed']),
                SelectFilter::make('type')
                    ->options([
                        'bar_handover' => 'Bar Handover',
                        'kitchen_handover' => 'Kitchen Handover',
                        'main_store_stocktake' => 'Main Store Stocktake',
                    ]),
                SelectFilter::make('outgoing_user_id')
                    ->label('Bartender/Chef')
                    ->relationship('outgoingUser', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('opened_between')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('opened_at', '>=', $date))
                            ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('opened_at', '<=', $date));
                    }),
            ])
            ->recordUrl(fn (CountSession $record) => "/admin/count-session-detail?session_id={$record->id}")
            ->headerActions([
                Action::make('newSession')
                    ->label('New Session')
                    ->form([
                        Select::make('type')
                            ->label('Session Type')
                            ->options([
                                'bar_handover' => 'Bar Handover',
                                'kitchen_handover' => 'Kitchen Handover',
                                'main_store_stocktake' => 'Main Store Stocktake',
                            ])
                            ->required()
                            ->live(),

                        Select::make('warehouse_id')
                            ->label('Warehouse')
                            ->options(fn () => WareHouse::pluck('name', 'id'))
                            ->required(),

                        Select::make('outgoing_user_id')
                            ->label('Outgoing Custodian')
                            ->options(fn () => User::pluck('name', 'id'))
                            ->visible(fn (callable $get) => in_array($get('type'), ['bar_handover', 'kitchen_handover'], true))
                            ->required(fn (callable $get) => in_array($get('type'), ['bar_handover', 'kitchen_handover'], true)),

                        Select::make('incoming_user_id')
                            ->label('Incoming Custodian')
                            ->options(fn () => User::pluck('name', 'id'))
                            ->visible(fn (callable $get) => in_array($get('type'), ['bar_handover', 'kitchen_handover'], true))
                            ->required(fn (callable $get) => in_array($get('type'), ['bar_handover', 'kitchen_handover'], true)),

                        Textarea::make('notes')->label('Notes'),
                    ])
                    ->action(function (array $data, Action $action) {
                        try {
                            $session = (new CountSessionService())->openSession(
                                $data['type'],
                                $data['warehouse_id'],
                                auth()->id(),
                                $data['outgoing_user_id'] ?? null,
                                $data['incoming_user_id'] ?? null,
                                $data['notes'] ?? null,
                            );

                            Notification::make()->title('Count session opened')->success()->send();

                            $action->redirect("/admin/count-session-detail?session_id={$session->id}");
                        } catch (\Exception $e) {
                            Notification::make()->title('Could not open session')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }
}
