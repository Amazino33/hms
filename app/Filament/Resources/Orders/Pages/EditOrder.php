<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Services\UserFeedback;
use Filament\Resources\Pages\EditRecord;

/**
 * Phase 0F: an order's status and lines are no longer editable here — that
 * changed the bill with no stock movement. The form shows them read-only;
 * this page also refuses a save that tries to change them anyway (a
 * tampered request), and has no Delete button (deleting an order silently
 * took its lines with it). The only thing left to edit is the
 * cancellation reason on a cancelled order.
 */
class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    public const LOCKED_MESSAGE = 'Use Mark Ready, Void or Return — orders can\'t be edited directly.';

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function beforeSave(): void
    {
        $record = $this->getRecord();
        $state = $this->data;

        $statusChanged = array_key_exists('status', $state) && $state['status'] !== $record->status;

        $submittedLines = collect($state['items'] ?? [])
            ->map(fn ($line) => [(int) ($line['product_id'] ?? 0), (int) ($line['quantity'] ?? 0)])
            ->sort()->values()->all();
        $currentLines = $record->items()->get()
            ->map(fn ($line) => [(int) $line->product_id, (int) $line->quantity])
            ->sort()->values()->all();
        $linesChanged = array_key_exists('items', $state) && $submittedLines !== $currentLines;

        $totalChanged = array_key_exists('total_amount', $state)
            && round((float) str_replace(',', '', (string) $state['total_amount']), 2) !== round((float) $record->total_amount, 2);

        if ($statusChanged || $linesChanged || $totalChanged) {
            UserFeedback::blocked('Order not changed', self::LOCKED_MESSAGE);

            $this->halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
