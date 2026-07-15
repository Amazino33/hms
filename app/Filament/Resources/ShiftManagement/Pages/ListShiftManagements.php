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
            'Awaiting Cashier' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'awaiting_cashier')),
            // Bartender/chef shifts never reach 'awaiting_cashier' (their
            // dual-PIN handover seal writes 'closed' directly) — both
            // terminal strings count as done here.
            'Completed' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', ['confirmed', 'closed'])),
        ];
    }
}
