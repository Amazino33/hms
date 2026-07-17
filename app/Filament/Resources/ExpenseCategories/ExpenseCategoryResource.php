<?php

namespace App\Filament\Resources\ExpenseCategories;

use App\Filament\Resources\ExpenseCategories\Pages\CreateExpenseCategory;
use App\Filament\Resources\ExpenseCategories\Pages\EditExpenseCategory;
use App\Filament\Resources\ExpenseCategories\Pages\ListExpenseCategories;
use App\Models\ExpenseCategory;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Super-admin only (see the Policy + ShieldSeeder entry). A fixed, seeded
 * list — add/rename/deactivate, never delete, so a historical Expense
 * always keeps a valid category reference.
 */
class ExpenseCategoryResource extends Resource
{
    protected static ?string $model = ExpenseCategory::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-tag';
    protected static string|UnitEnum|null $navigationGroup = 'Finance';
    protected static ?string $navigationLabel = 'Expense Categories';
    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            TextInput::make('name')->required()->maxLength(255)->unique(ignoreRecord: true),
            Toggle::make('is_active')->label('Active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                IconColumn::make('is_active')->boolean()->label('Active'),
                TextColumn::make('expenses_count')->label('Expenses')->counts('expenses'),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenseCategories::route('/'),
            'create' => CreateExpenseCategory::route('/create'),
            'edit' => EditExpenseCategory::route('/{record}/edit'),
        ];
    }
}
