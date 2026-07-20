<?php

namespace App\Filament\Resources\ProductDeletionRequests;

use App\Filament\Resources\ProductDeletionRequests\Pages\ListProductDeletionRequests;
use App\Models\ProductDeletionRequest;
use App\Services\ProductDeletionService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class ProductDeletionRequestResource extends Resource
{
    protected static ?string $model = ProductDeletionRequest::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-trash';

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Product Deletion Requests';

    protected static ?string $recordTitleAttribute = 'id';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('#'),
                TextColumn::make('product.name')
                    ->label('Product')
                    ->formatStateUsing(fn (ProductDeletionRequest $record) => $record->product?->name.($record->product?->trashed() ? ' (deleted)' : '')),
                TextColumn::make('reason')
                    ->label('Reason')
                    ->limit(60),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'pending' => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('requestedBy.name')
                    ->label('Requested By'),
                TextColumn::make('reviewedBy.name')
                    ->label('Reviewed By')
                    ->default('—'),
                TextColumn::make('created_at')
                    ->label('Requested At')
                    ->dateTime('M j, Y g:i A'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected']),
            ])
            ->recordActions([
                Action::make('view_history')
                    ->label('View History')
                    ->color('gray')
                    ->icon('heroicon-o-clock')
                    ->url(fn (ProductDeletionRequest $record) => "/admin/product-history?product_id={$record->product_id}"),

                Action::make('approve')
                    ->label('Approve')
                    ->color('success')
                    ->icon('heroicon-o-check-circle')
                    ->requiresConfirmation()
                    ->modalDescription('This will soft-delete the product. All its inventory, transaction, adjustment, and count history is preserved and stays visible on its History page — only super_admin can restore it afterward.')
                    ->visible(fn (ProductDeletionRequest $record) => $record->isPending()
                        && $record->requested_by !== auth()->id()
                        && auth()->user()->can('update', $record))
                    ->action(function (ProductDeletionRequest $record) {
                        try {
                            (new ProductDeletionService)->approve($record, auth()->user());

                            Notification::make()->title('Deletion approved')->success()->send();
                        } catch (\Exception $e) {
                            Notification::make()->title('Could not approve')->body($e->getMessage())->danger()->persistent()->send();
                        }
                    }),

                Action::make('reject')
                    ->label('Reject')
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->form([
                        Textarea::make('rejection_reason')->required()->label('Reason for rejection'),
                    ])
                    ->visible(fn (ProductDeletionRequest $record) => $record->isPending()
                        && $record->requested_by !== auth()->id()
                        && auth()->user()->can('update', $record))
                    ->action(function (ProductDeletionRequest $record, array $data) {
                        try {
                            (new ProductDeletionService)->reject($record, auth()->user(), $data['rejection_reason']);

                            Notification::make()->title('Deletion request rejected')->success()->send();
                        } catch (\Exception $e) {
                            Notification::make()->title('Could not reject')->body($e->getMessage())->danger()->persistent()->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductDeletionRequests::route('/'),
        ];
    }
}
