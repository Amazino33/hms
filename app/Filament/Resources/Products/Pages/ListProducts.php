<?php

namespace App\Filament\Resources\Products\Pages;

use App\Exports\ProductTemplateExport;
use App\Filament\Resources\Products\ProductResource;
use App\Models\WareHouse;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\HtmlString;
use Maatwebsite\Excel\Facades\Excel as FacadesExcel;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('download_template')
                ->label('Download Template')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                
                // 1. The Modal Form (This asks for the Warehouse)
                ->schema([
                    Select::make('warehouse_id')
                        ->label('Select Target Warehouse')
                        ->options(WareHouse::pluck('name', 'id')) // Get list from DB
                        ->required()
                        ->native(false),
                ])
                
                // 2. The Action (Runs after they click "Submit" in the modal)
                ->action(function (array $data) {
                    // Find the name of the selected warehouse
                    $warehouseName = Warehouse::find($data['warehouse_id'])->name;

                    // Download the file, passing the name to the Export class
                    return FacadesExcel::download(
                        new ProductTemplateExport($warehouseName), 
                        'import_template_' . strtolower(str_replace(' ', '_', $warehouseName)) . '.xlsx'
                    );
                }),

            \Filament\Actions\CreateAction::make(),
        ];
    }
}
