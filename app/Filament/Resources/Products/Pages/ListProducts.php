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
                ->label('Download Warehouse Import Template')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(function () {
                    // Use the first storage warehouse for import template
                    $storageWarehouse = WareHouse::where('type', 'storage')->first();
                    $warehouseName = $storageWarehouse ? $storageWarehouse->name : 'Storage Warehouse';

                    // Download the file for storage warehouse
                    return FacadesExcel::download(
                        new ProductTemplateExport($warehouseName), 
                        'warehouse_import_template.xlsx'
                    );
                }),

            \Filament\Actions\CreateAction::make(),
        ];
    }
}
