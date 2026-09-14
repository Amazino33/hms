<?php

namespace App\Filament\Ceo\Resources\SalaryDeductions;

use App\Filament\Ceo\Resources\SalaryDeductions\Pages\ManageSalaryDeductions;
use App\Models\SalaryDeduction;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SalaryDeductionResource extends Resource
{
    protected static ?string $model = SalaryDeduction::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes'; 

    protected static ?string $modelLabel = 'Surcharge';
    protected static ?string $pluralModelLabel = 'Surcharges';
    protected static ?string $navigationLabel = 'Surcharges';
    protected static ?string $slug = 'surcharges';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Read-only
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('user.name')
                    ->label('Staff Member')
                    ->searchable()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('amount')
                    ->money('ngn')
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('date')
                    ->date()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('reason')
                    ->wrap(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                //
            ])
            ->recordActions([
                
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSalaryDeductions::route('/'),
        ];
    }
}
