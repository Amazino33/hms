<?php

namespace App\Filament\Resources\StaffDebts\Schemas;

use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class StaffDebtForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->label('Staff Member')
                    ->options(fn () => User::whereHas('roles', fn ($q) => $q->whereIn('name', ['waiter', 'porter', 'bartender', 'chef', 'storekeeper']))->pluck('name', 'id'))
                    ->searchable()
                    ->required(),

                TextInput::make('amount')
                    ->numeric()
                    ->prefix('₦')
                    ->required()
                    ->minValue(0.01),

                Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }
}
