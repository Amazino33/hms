<?php

namespace App\Filament\Resources\Suppliers\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class SupplierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                TextInput::make('contact_person')
                    ->maxLength(255),

                TextInput::make('phone')
                    ->tel()
                    ->maxLength(255),

                TextInput::make('email')
                    ->email()
                    ->maxLength(255),

                Textarea::make('address')
                    ->columnSpanFull(),
            ]);
    }
}