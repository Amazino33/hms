<?php

namespace App\Filament\Resources\Guests\RelationManagers;

use App\Filament\Resources\Orders\OrderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Filament\Forms;
use Filament\Tables;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';
    protected static ?string $recordTitleAttribute = 'order_number';
    protected static ?string $relatedResource = OrderResource::class;

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order_number')
                    ->label('Order #')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('M j, Y • g:i A')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_amount')
                    ->money('NGN')
                    ->label('Total'),

                // The "Ledger" Column: Shows how much they actually paid
                Tables\Columns\TextColumn::make('amount_paid')
                    ->money('NGN')
                    ->label('Paid')
                    ->color('success'),

                // The Debt Column
                Tables\Columns\TextColumn::make('debt')
                    ->label('Balance Due')
                    ->money('NGN')
                    ->state(fn (Order $record) => max(0, $record->total_amount - $record->amount_paid))
                    ->color('danger')
                    ->weight('bold')
                    ->description(fn (Order $record) => $record->status === 'partial' ? 'Unpaid' : null),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'partial' => 'danger',
                        'paid' => 'success',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('created_at', 'desc') // Show newest first
            ->recordActions([
                // 🔥 THE "SETTLE DEBT" ACTION
                Action::make('settle_debt')
                    ->label('Add Payment')
                    ->icon('heroicon-m-banknotes')
                    ->color('success')
                    ->visible(fn (Order $record) => $record->status === 'partial')
                    ->requiresConfirmation()
                    ->modalHeading('Receive Payment')
                    ->modalDescription(fn (Order $record) => 'The customer owes ₦' . number_format($record->total_amount - $record->amount_paid) . '. How much is the guest paying?')
                    ->schema([
                        TextInput::make('amount_paying')
                            ->label('Amount Received (₦)')
                            ->numeric()
                            ->required()
                            ->prefix('₦')
                            ->autofocus()
                            // Helper: Show the max they can pay
                            ->helperText(fn (Order $record) => 'Max payable: ₦' . number_format($record->total_amount - $record->amount_paid))
                            // Validation: Don't let them pay more than the debt
                            ->maxValue(fn (Order $record) => $record->total_amount - $record->amount_paid),
                        Select::make('method')
                            ->label('Payment Method')
                            ->options([
                                'cash' => 'Cash',
                                'pos' => 'POS',
                                'transfer' => 'Transfer',
                            ])
                            ->default('cash')
                            ->required(),
                    ])
                    ->action(function (Order $record, array $data) {
                        // 1. Create the PROOF (The Log)
                        $record->payments()->create([
                            'amount' => $data['amount_paying'],
                            'method' => $data['method'],
                            'user_id' => auth()->id(), // Who collected it?
                            'paid_at' => now(),
                        ]);

                        // 2. Calculate new total paid
                        $newAmountPaid = $record->amount_paid + $data['amount_paying'];
                        $newStatus = ($newAmountPaid >= ($record->total_amount - 0.1)) ? 'paid' : 'partial';

                        // 3. Update the Order
                        $record->update([
                            'amount_paid' => $newAmountPaid,
                            'status' => $newStatus,
                        ]);

                        // 4. Notify
                        $remaining = $record->total_amount - $newAmountPaid;
                        
                        if ($newStatus === 'paid') {
                            Notification::make()->title('Debt Cleared!')->success()->send();
                        } else {
                            Notification::make()
                                ->title('Payment Recorded')
                                ->body('Remaining Debt: ₦' . number_format($remaining))
                                ->warning()
                                ->send();
                        }
                    }),
                    
                    // ACTION 2: VIEW PAYMENT HISTORY (The Evidence)
                    Action::make('view_history')
                        ->label('History')
                        ->icon('heroicon-m-clock')
                        ->color('gray')
                        ->modalHeading('Payment History')
                        ->modalContent(fn (Order $record) => view('filament.pages.payment-history-modal', ['payments' => $record->payments()->orderBy('paid_at', 'desc')->get()]))
                        ->modalSubmitAction(false) // Remove "Submit" button, just "Cancel"
                        ->modalCancelActionLabel('Close'),
                // Keep the "View" action so you can see what they ordered
                ViewAction::make(),
            ]);
    }
}
