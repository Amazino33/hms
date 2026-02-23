<?php

namespace App\Filament\Resources\ShiftManagement;

use App\Filament\Resources\ShiftManagement\Pages\ListShiftManagements;
use App\Filament\Resources\ShiftManagement\Pages\ViewShiftManagement;
use App\Filament\Resources\ShiftManagement\Schemas\ShiftManagementInfolist;
use App\Filament\Resources\ShiftManagement\Tables\ShiftManagementTable;
use App\Models\Shift;
use App\Services\PermissionService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class ShiftManagementResource extends Resource
{
    protected static ?string $model = Shift::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string|UnitEnum|null $navigationGroup = 'Management';

    protected static ?string $navigationLabel = 'Shift Management';

    protected static ?string $recordTitleAttribute = 'id';

    // Register with PermissionService for page access control
    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    public static function table(Table $table): Table
    {
        return ShiftManagementTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ShiftManagementInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShiftManagements::route('/'),
            'view' => ViewShiftManagement::route('/{record}'),
        ];
    }
}
