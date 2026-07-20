<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Services\ProductDeletionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('view_history')
                ->label('History')
                ->color('gray')
                ->icon('heroicon-o-clock')
                ->url(fn () => "/admin/product-history?product_id={$this->record->id}"),

            // See ProductsTable's request_deletion action for why this
            // replaces a plain DeleteAction: a hard delete would cascade
            // away this product's entire inventory/transaction/adjustment/
            // count history.
            Action::make('request_deletion')
                ->label(fn () => auth()->user()->hasRole('super_admin') ? 'Delete Product' : 'Request Deletion')
                ->color('danger')
                ->icon('heroicon-o-trash')
                ->form([
                    Textarea::make('reason')->required()->label('Reason for deletion'),
                ])
                ->requiresConfirmation(fn () => auth()->user()->hasRole('super_admin'))
                ->modalDescription('This soft-deletes the product immediately — there is no one else to review it. Its inventory/transaction/adjustment/count history is kept, and it can be restored from the trashed filter.')
                ->visible(fn () => auth()->user()->can('delete', $this->record))
                ->action(function (array $data) {
                    $isSuperAdmin = auth()->user()->hasRole('super_admin');

                    try {
                        if ($isSuperAdmin) {
                            (new ProductDeletionService)->deleteImmediately($this->record, auth()->user(), $data['reason']);
                            Notification::make()->title('Product deleted')->success()->send();
                            $this->redirect($this->getResource()::getUrl('index'));

                            return;
                        } else {
                            (new ProductDeletionService)->request($this->record, $data['reason'], auth()->id());
                            Notification::make()->title('Deletion request submitted')->body('A different reviewer must approve it before anything is deleted.')->success()->send();
                        }
                    } catch (\Exception $e) {
                        Notification::make()->title('Could not delete product')->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
