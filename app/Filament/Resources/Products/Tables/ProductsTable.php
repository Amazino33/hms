<?php

namespace App\Filament\Resources\Products\Tables;

use App\Imports\ProductImport;
use App\Services\ProductDeletionService;
use Filament\Actions\Action as ActionsAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
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
                TrashedFilter::make(),
            ])
            ->paginated([10, 25, 50, 100])
            ->recordActions([
                EditAction::make(),

                ActionsAction::make('view_history')
                    ->label('History')
                    ->color('gray')
                    ->icon('heroicon-o-clock')
                    ->url(fn ($record) => "/admin/product-history?product_id={$record->id}"),

                // Deliberately not a DeleteAction: a hard delete cascades
                // away every inventory/transaction/adjustment/count record
                // for this product — the exact accountability trail a
                // dishonest deletion would be trying to erase. This only
                // ever opens a pending request; ProductDeletionService
                // enforces that a different person (never the requester)
                // must approve it before the product is even soft-deleted.
                ActionsAction::make('request_deletion')
                    ->label('Request Deletion')
                    ->color('danger')
                    ->icon('heroicon-o-trash')
                    ->form([
                        Textarea::make('reason')->required()->label('Reason for deletion'),
                    ])
                    ->visible(fn ($record) => ! $record->trashed() && auth()->user()->can('delete', $record))
                    ->action(function ($record, array $data) {
                        try {
                            (new ProductDeletionService)->request($record, $data['reason'], auth()->id());

                            Notification::make()->title('Deletion request submitted')->body('A different reviewer must approve it before anything is deleted.')->success()->send();
                        } catch (\Exception $e) {
                            Notification::make()->title('Could not submit request')->body($e->getMessage())->danger()->persistent()->send();
                        }
                    }),

                // Undo for a wrongly-approved deletion — deliberately
                // restricted to super_admin, both here and again inside
                // ProductDeletionService::restore(), so hiding the button
                // is never the only thing standing in the way.
                RestoreAction::make()
                    ->visible(fn ($record) => $record->trashed() && auth()->user()->hasRole('super_admin')),
            ])
            ->toolbarActions([
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
                            $tempFile = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
                            file_put_contents($tempFile, $fileContent);

                            Excel::import(new ProductImport, $tempFile);

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
                                ->persistent()
                                ->send();
                        }
                    })
                    ->modalHeading('Import Products from Excel')
                    ->modalDescription('Upload an Excel file with product data. Make sure it follows the template format.')
                    ->modalSubmitActionLabel('Import'),
            ]);
    }
}
