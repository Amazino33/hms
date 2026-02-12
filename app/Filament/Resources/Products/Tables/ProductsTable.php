<?php

namespace App\Filament\Resources\Products\Tables;

use App\Imports\ProductImport;
use Filament\Actions\Action as ActionsAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable(),
                TextColumn::make('category.name')
                    ->label('Category')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('price')
                    ->money('NGN')
                    ->sortable(),
                TextColumn::make('cost_price')
                    ->money('NGN')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->paginated([10, 25, 50, 100])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
                ExportBulkAction::make(),
                ActionsAction::make('import_products')
                    ->label('Import Products')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('success')
                    ->form([
                        FileUpload::make('file')
                            ->label('Excel File')
                            ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'])
                            ->required()
                            ->maxSize(10240) // 10MB
                            ->directory('imports')
                            ->disk('public'), // Use public disk for easier access
                    ])
                    ->action(function (array $data) {
                        // Get the uploaded file content
                        $filePath = $data['file'];
                        
                        try {
                            // Use Storage to get the file content and import directly
                            $fileContent = Storage::disk('public')->get($filePath);
                            $tempFile = tempnam(sys_get_temp_dir(), 'import');
                            file_put_contents($tempFile, $fileContent);
                            
                            Excel::import(new ProductImport(), $tempFile);
                            
                            // Clean up
                            unlink($tempFile);
                            Storage::disk('public')->delete($filePath);
                            
                            \Filament\Notifications\Notification::make()
                                ->title('Import completed successfully')
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            // Clean up on error
                            if (isset($tempFile) && file_exists($tempFile)) {
                                unlink($tempFile);
                            }
                            if (Storage::disk('public')->exists($filePath)) {
                                Storage::disk('public')->delete($filePath);
                            }
                            
                            \Filament\Notifications\Notification::make()
                                ->title('Import failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->modalHeading('Import Products from Excel')
                    ->modalDescription('Upload an Excel file with product data. Make sure it follows the template format.')
                    ->modalSubmitActionLabel('Import'),
            ]);
    }
}
