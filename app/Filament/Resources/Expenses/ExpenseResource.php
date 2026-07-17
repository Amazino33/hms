<?php

namespace App\Filament\Resources\Expenses;

use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\ExpenseService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Append-only with void — no 'edit' route exists here at all (deliberately,
 * matching StockAdjustmentResource's own precedent for "no editing after
 * creation"). amount/category/date_incurred are immutable once posted;
 * the only two things a manager can still do to an existing row are edit
 * its note (a small action, not a full edit form) or void it. Manager +
 * super-admin only, via ExpensePolicy.
 */
class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';
    protected static string|UnitEnum|null $navigationGroup = 'Finance';
    protected static ?string $navigationLabel = 'Expenses';
    protected static ?string $recordTitleAttribute = 'id';

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Placeholder::make('procurement_notice')
                ->label('')
                ->content('Do not enter inventory procurement here — purchases of stock (drinks, food ingredients, etc.) are already captured with their cost prices in the storekeeper module. Entering them here too would double-count them against future profit reporting.'),
            TextInput::make('amount')
                ->numeric()
                ->minValue(0.01)
                ->required()
                ->prefix('₦'),
            Select::make('expense_category_id')
                ->label('Category')
                ->options(fn () => ExpenseCategory::where('is_active', true)->pluck('name', 'id'))
                ->required()
                ->searchable(),
            DatePicker::make('date_incurred')
                ->label('Date incurred')
                ->default(now()->toDateString())
                ->required(),
            Textarea::make('note'),
            FileUpload::make('receipt_photo')
                ->image()
                ->directory('expenses')
                ->disk('public'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date_incurred', 'desc')
            ->columns([
                TextColumn::make('date_incurred')->date('d M Y')->sortable(),
                TextColumn::make('category.name')->label('Category'),
                TextColumn::make('amount')->money('NGN')->sortable(),
                TextColumn::make('note')->limit(40)->default('—'),
                TextColumn::make('enteredBy.name')->label('Entered by')->default('—'),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (Expense $record) => $record->isVoided() ? 'Voided' : 'Active')
                    ->badge()
                    ->color(fn (Expense $record) => $record->isVoided() ? 'danger' : 'success'),
            ])
            ->filters([
                SelectFilter::make('expense_category_id')
                    ->label('Category')
                    ->options(fn () => ExpenseCategory::pluck('name', 'id')),
                TernaryFilter::make('voided')
                    ->label('Voided')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('voided_at'),
                        false: fn ($query) => $query->whereNull('voided_at'),
                    ),
            ])
            ->recordActions([
                Action::make('editNote')
                    ->label('Edit note')
                    ->icon('heroicon-o-pencil')
                    ->form([
                        Textarea::make('note')->default(fn (Expense $record) => $record->note),
                    ])
                    ->action(function (Expense $record, array $data) {
                        app(ExpenseService::class)->updateNote($record, $data['note'] ?? null);
                        Notification::make()->success()->title('Note updated')->send();
                    }),
                Action::make('void')
                    ->label('Void')
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->visible(fn (Expense $record) => ! $record->isVoided())
                    ->form([
                        Textarea::make('void_reason')->label('Reason')->required(),
                    ])
                    ->action(function (Expense $record, array $data) {
                        try {
                            app(ExpenseService::class)->void($record, auth()->id(), $data['void_reason']);
                            Notification::make()->success()->title('Expense voided')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Could not void')->body($e->getMessage())->persistent()->send();
                        }
                    }),
            ])
            ->headerActions([
                CreateAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenses::route('/'),
            'create' => CreateExpense::route('/create'),
        ];
    }
}
