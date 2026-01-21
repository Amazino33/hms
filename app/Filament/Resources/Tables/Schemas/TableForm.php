<?php

namespace App\Filament\Resources\Tables\Schemas;

use Dom\Text;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section; // Section is in Schemas
use Filament\Schemas\Schema;

class TableForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
                    ->schema([
                Section::make('Table Details')
                    ->schema([
                        // 1. Name (Unique Check)
                        TextInput::make('name')
                            ->label('Table Name / Number')
                            ->required()
                            ->unique(ignoreRecord: true) // Unique, but ignore self when editing
                            ->placeholder('e.g., Table 5 or VIP 1'),

                        // 2. Capacity
                        TextInput::make('capacity')
                            ->numeric()
                            ->default(4)
                            ->required()
                            ->minValue(1)
                            ->label('Seating Capacity'),

                        // 3. Status
                        Select::make('status')
                            ->options([
                                'available' => 'Available',
                                'occupied' => 'Occupied',
                                'reserved' => 'Reserved',
                            ])
                            ->default('available')
                            ->required()
                            ->native(false),

                        // 4. Location
                        TextInput::make('location')
                            ->placeholder('e.g., Indoor, Patio, Rooftop')
                            ->maxLength(255),
                    ])->columns(2),
            ]);
    }
}
