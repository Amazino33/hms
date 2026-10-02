<?php

namespace App\Filament\Resources\ChipGroups;

use App\Filament\Resources\ChipGroups\Pages\CreateChipGroup;
use App\Filament\Resources\ChipGroups\Pages\EditChipGroup;
use App\Filament\Resources\ChipGroups\Pages\ListChipGroups;
use App\Models\ChipGroup;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * "Menu chips" (Phase 1A): the quick choices guests tap on an item —
 * Temperature on drinks, Food extras on food. Groups and options are
 * switched off, never deleted, so nothing that referred to them breaks;
 * order items already keep their own copy of the label anyway.
 *
 * Gated by ChipGroupPolicy (Shield), like every resource.
 */
class ChipGroupResource extends Resource
{
    protected static ?string $model = ChipGroup::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-plus';

    protected static string|UnitEnum|null $navigationGroup = 'Menu Management';

    protected static ?string $navigationLabel = 'Menu chips';

    protected static ?string $modelLabel = 'chip group';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make()->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(60)
                    ->helperText('e.g. "Temperature" or "Pepper".'),
                Select::make('selection')
                    ->options(ChipGroup::SELECTIONS)
                    ->default('single')
                    ->required(),
                TextInput::make('sort_order')->numeric()->default(0)->minValue(0),
                Toggle::make('active')->default(true),
                Select::make('categories')
                    ->relationship('categories', 'name')
                    ->multiple()
                    ->preload()
                    ->helperText('Offered on every item in these categories.')
                    ->columnSpanFull(),
            ])->columns(2),

            Repeater::make('options')
                ->relationship('options')
                ->schema([
                    TextInput::make('label')->required()->maxLength(40),
                    TextInput::make('sort_order')->numeric()->default(0)->minValue(0),
                    Toggle::make('active')->default(true)->inline(false),
                ])
                ->columns(3)
                ->orderColumn('sort_order')
                ->deletable(false)
                ->addActionLabel('+ Add chip')
                ->helperText('Switch a chip off instead of removing it. Renaming one never changes orders already placed.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('selection')->formatStateUsing(fn (string $state) => ChipGroup::SELECTIONS[$state] ?? $state),
                TextColumn::make('options.label')->label('Chips')->listWithLineBreaks()->limitList(4),
                TextColumn::make('categories.name')->label('Categories')->badge(),
                IconColumn::make('active')->boolean(),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChipGroups::route('/'),
            'create' => CreateChipGroup::route('/create'),
            'edit' => EditChipGroup::route('/{record}/edit'),
        ];
    }
}
