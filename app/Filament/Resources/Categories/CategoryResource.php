<?php

namespace App\Filament\Resources\Categories;

use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Models\Category;
use App\Services\UserFeedback;
use BackedEnum;
use UnitEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
// use Filament\Forms\Form; // Removed, not needed
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static string|UnitEnum|null $navigationGroup = 'Menu Management';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Select::make('type')
                    ->options([
                        'food' => 'Food',
                        'drink' => 'Drink',
                        'service' => 'Service',
                    ])
                    ->required(),
                TextInput::make('commission_rate')
                    ->label('Waiter Commission (₦ per item sold)')
                    ->helperText('Fixed ₦ amount credited to the waiter per unit of any item in this category. Leave 0 for no commission.')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->suffix('₦ / unit')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                BadgeColumn::make('type')
                    ->colors([
                        'primary' => 'food',
                        'warning' => 'drink',
                        'success' => 'service',
                    ]),
                TextColumn::make('commission_rate')
                    ->label('Commission (₦/unit)')
                    ->money('NGN', 0)
                    ->sortable(),
            ])
        ->recordActions([
            Action::make('edit')
                ->requiresConfirmation()
                ->label('Edit')
                ->icon('heroicon-o-pencil')
                ->url(fn (Category $record) => EditCategory::getUrl(['record' => $record])),            
            Action::make('delete')
                ->requiresConfirmation()
                ->label('Delete')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->action(function (Category $record) {
                    try {
                        $record->delete();
                        UserFeedback::succeeded('Category deleted');
                    } catch (\Throwable $e) {
                        report($e);
                        UserFeedback::blocked('Cannot delete category', 'This category still has products assigned to it. Move or delete those products first.');
                    }
                }),
        ])
        ->paginated([10, 25, 50, 100])
        ->toolbarActions([
            BulkAction::make('delete')
                ->requiresConfirmation()
                ->label('Delete Selected')
                ->icon('heroicon-o-trash')
                ->action(function (array $records) {
                    try {
                        $count = Category::whereIn('id', $records)->delete();
                        UserFeedback::succeeded("{$count} categor" . ($count === 1 ? 'y' : 'ies') . ' deleted');
                    } catch (\Throwable $e) {
                        report($e);
                        UserFeedback::blocked('Could not delete', 'One or more selected categories still have products assigned to them.');
                    }
                }),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
            'edit' => EditCategory::route('/{record}/edit'),
        ];
    }
}
