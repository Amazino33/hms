<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductDeletionRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProductDeletionService
{
    /**
     * Create a pending deletion request. This never touches the product —
     * only an approval (by someone other than the requester) soft-deletes
     * it. No exception for a zero-history product: one rule, no edge cases.
     *
     * @throws \Exception
     */
    public function request(Product $product, string $reason, int $requestedByUserId): ProductDeletionRequest
    {
        if ($product->deletionRequests()->where('status', 'pending')->exists()) {
            throw new \Exception('A deletion request for this product is already pending.');
        }

        return ProductDeletionRequest::create([
            'product_id' => $product->id,
            'reason' => $reason,
            'status' => 'pending',
            'requested_by' => $requestedByUserId,
        ]);
    }

    /**
     * Approve a pending request, soft-deleting the product. Four-eyes is
     * enforced unconditionally here: the reviewer can never be the
     * requester, regardless of role — same rule as StockAdjustmentService,
     * no direct-apply exception for managers or super_admin. Being soft
     * deletion, not one row of inventory/transaction/adjustment/count
     * history is touched — it all stays linked to the product exactly as
     * it was.
     *
     * @throws \Exception
     */
    public function approve(ProductDeletionRequest $request, User $reviewer): ProductDeletionRequest
    {
        if (! $request->isPending()) {
            throw new \Exception('Only pending deletion requests can be approved.');
        }

        if ($request->requested_by === $reviewer->id) {
            throw new \Exception('A product deletion request cannot be approved by the same person who requested it.');
        }

        if ($reviewer->cannot('update', $request)) {
            throw new \Exception('You do not have permission to approve product deletion requests.');
        }

        $reviewedByUserId = $reviewer->id;

        return DB::transaction(function () use ($request, $reviewedByUserId) {
            $request->product?->delete();

            $request->update([
                'status' => 'approved',
                'reviewed_by' => $reviewedByUserId,
                'reviewed_at' => now(),
            ]);

            return $request->fresh();
        });
    }

    /**
     * Reject a pending request. The product is never touched. Four-eyes
     * applies here too — a requester cannot reject (i.e. review) their own
     * request.
     *
     * @throws \Exception
     */
    public function reject(ProductDeletionRequest $request, User $reviewer, string $rejectionReason): ProductDeletionRequest
    {
        if (! $request->isPending()) {
            throw new \Exception('Only pending deletion requests can be rejected.');
        }

        if ($request->requested_by === $reviewer->id) {
            throw new \Exception('A product deletion request cannot be reviewed by the same person who requested it.');
        }

        if ($reviewer->cannot('update', $request)) {
            throw new \Exception('You do not have permission to review product deletion requests.');
        }

        $request->update([
            'status' => 'rejected',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'rejection_reason' => $rejectionReason,
        ]);

        return $request->fresh();
    }

    /**
     * Undo a wrongly-approved deletion. Restricted to super_admin only —
     * checked here as well as at the UI layer, since this is the one path
     * that can put a deleted product back in front of every other role
     * without a second request/approval cycle.
     *
     * @throws \Exception
     */
    public function restore(Product $product, User $restorer): Product
    {
        if (! $restorer->hasRole('super_admin')) {
            throw new \Exception('Only a super_admin can restore a deleted product.');
        }

        if (! $product->trashed()) {
            throw new \Exception('This product is not deleted.');
        }

        $product->restore();

        return $product->fresh();
    }
}
