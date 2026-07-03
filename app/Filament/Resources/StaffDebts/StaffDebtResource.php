<?php

namespace App\Filament\Resources\StaffDebts;

use App\Filament\Resources\StaffDebts\Pages\CreateStaffDebt;
use App\Filament\Resources\StaffDebts\Pages\ListStaffDebts;
use App\Filament\Resources\StaffDebts\Schemas\StaffDebtForm;
use App\Filament\Resources\StaffDebts\Tables\StaffDebtsTable;
use App\Models\StaffDebt;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class StaffDebtResource extends Resource
{
    protected static ?string $model = StaffDebt::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static string|UnitEnum|null $navigationGroup = 'Management';

    protected static ?string $navigationLabel = 'Staff Debts';

    protected static ?string $recordTitleAttribute = 'id';

    public static function form(Schema $schema): Schema
    {
        return StaffDebtForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StaffDebtsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaffDebts::route('/'),
            'create' => CreateStaffDebt::route('/create'),
        ];
    }
}
