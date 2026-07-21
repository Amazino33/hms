<?php

namespace App\Filament\Ceo\Resources\OwnerTakeNotes;

use App\Filament\Ceo\Concerns\CeoReadOnlyResource;
use App\Filament\Ceo\Resources\OwnerTakeNotes\Pages\ListOwnerTakeNotes;
use App\Models\OwnerTakeNote;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class OwnerTakeNoteResource extends Resource
{
    use CeoReadOnlyResource;

    protected static ?string $model = OwnerTakeNote::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-hand-raised';

    protected static string|UnitEnum|null $navigationGroup = 'Records';

    protected static ?string $navigationLabel = "Oga's Take Notes";

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('recordedBy.name')->label('Noted By'),
                TextColumn::make('shift.type')->label('Role')->badge(),
                TextColumn::make('amount')->money('NGN')->placeholder('—'),
                TextColumn::make('description')->wrap(),
                TextColumn::make('created_at')->label('Noted At')->dateTime('M j, Y g:ia'),
            ])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => ListOwnerTakeNotes::route('/')];
    }
}
