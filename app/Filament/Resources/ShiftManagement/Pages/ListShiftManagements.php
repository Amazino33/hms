<?php

namespace App\Filament\Resources\ShiftManagement\Pages;

use App\Filament\Resources\ShiftManagement\ShiftManagementResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListShiftManagements extends ListRecords
{
    protected static string $resource = ShiftManagementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // No create action for supervisors
        ];
    }

    public function getTabs(): array
    {
        return [
            'All' => Tab::make(),
            'Requires Review' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending_supervisor')),
            'Completed' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'closed')),
        ];
    }
}
