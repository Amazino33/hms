<?php

namespace App\Services;

use App\Models\CountSession;
use App\Models\CountSessionItem;
use App\Models\CountSessionItemReview;
use App\Models\CountSessionSubCount;
use App\Models\HandoverDiscrepancy;
use App\Models\HandoverDiscrepancyRecount;
use App\Models\Ingredient;
use App\Models\IngredientInventoryItem;
use App\Models\IngredientTransaction;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Shift;
use App\Models\StaffDebt;
use App\Models\User;
use App\Models\WareHouse;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class CountSessionService
{
    private const ROLE_FOR_HANDOVER = [
        'bar_handover' => 'bartender',
        'kitchen_handover' => 'chef',
    ];

    /**
     * Open a new count session and snapshot every product/ingredient
     * currently tracked at this warehouse as the blind "expected" baseline.
     * Nothing about this baseline is ever exposed while status = 'counting'.
     */
    public function openSession(
        string $type,
        int $warehouseId,
        int $openedByUserId,
        ?int $outgoingUserId = null,
        ?int $incomingUserId = null,
        ?string $notes = null,
        bool $isClosing = false,
        ?int $witnessUserId = null,
    ): CountSession {
        $isHandover = in_array($type, ['bar_handover', 'kitchen_handover'], true);

        if ($isClosing && !$isHandover) {
            throw new \Exception('Only a bar/kitchen count can be a closing count.');
        }

        if ($witnessUserId && !$isHandover) {
            throw new \Exception('Only a bar/kitchen count can have a witness.');
        }

        if ($witnessUserId && $isClosing) {
            throw new \Exception('A closing count and an unwitnessed handover are two different things — pick one.');
        }

        if ($witnessUserId && !$outgoingUserId) {
            throw new \Exception('An unwitnessed handover still needs to name who the absent outgoing custodian is.');
        }

        if ($isHandover && !$incomingUserId) {
            throw new \Exception($isClosing
                ? 'A closing count requires a second person to confirm it.'
                : 'A handover count requires an incoming user.');
        }

        if ($isHandover && !$outgoingUserId && !$witnessUserId) {
            // No outgoing custodian named — only legitimate if this is truly
            // the first shift of the day (no active shift of that role yet).
            $role = self::ROLE_FOR_HANDOVER[$type];
            if (Shift::query()->ofType($role)->activeNonStale($role)->exists()) {
                throw new \Exception('An outgoing user is required — a shift for this role is already active.');
            }
        }

        return DB::transaction(function () use ($type, $warehouseId, $openedByUserId, $outgoingUserId, $incomingUserId, $notes, $isClosing, $witnessUserId) {
            $session = CountSession::create([
                'type' => $type,
                'warehouse_id' => $warehouseId,
                'status' => 'counting',
                'opened_by' => $openedByUserId,
                'opened_at' => now(),
                'outgoing_user_id' => $outgoingUserId,
                'incoming_user_id' => $incomingUserId,
                'witness_user_id' => $witnessUserId,
                'is_closing' => $isClosing,
                'notes' => $notes,
            ]);

            // Snapshotted at open time, not resolved live from the warehouse
            // each time — a label edited mid-count must not reshuffle an
            // already-open session's slots.
            $subLocationLabels = WareHouse::findOrFail($warehouseId)->subLocationLabels();

            if ($type === 'kitchen_handover') {
                $rows = IngredientInventoryItem::where('warehouse_id', $warehouseId)->get();
                foreach ($rows as $row) {
                    $item = CountSessionItem::create([
                        'count_session_id' => $session->id,
                        'item_type' => 'ingredient',
                        'ingredient_id' => $row->ingredient_id,
                        'expected_quantity_at_open' => $row->quantity,
                    ]);
                    $this->seedSubLocationSlots($item, $subLocationLabels);
                }
            } else {
                $rows = InventoryItem::where('warehouse_id', $warehouseId)->get();
                foreach ($rows as $row) {
                    $item = CountSessionItem::create([
                        'count_session_id' => $session->id,
                        'item_type' => 'product',
                        'product_id' => $row->product_id,
                        'expected_quantity_at_open' => $row->quantity,
                    ]);
                    $this->seedSubLocationSlots($item, $subLocationLabels);
                }
            }

            return $session;
        });
    }

    /**
     * Every item gets exactly the warehouse's 3 fixed sub-location slots,
     * pre-created at zero — counting only ever updates these rows, it never
     * creates new arbitrary sub-locations.
     */
    private function seedSubLocationSlots(CountSessionItem $item, array $labels): void
    {
        foreach ($labels as $label) {
            CountSessionSubCount::create([
                'count_session_item_id' => $item->id,
                'sub_location' => $label,
                'quantity' => 0,
            ]);
        }
    }

    /**
     * Record (or overwrite) the counted quantities for an item's fixed
     * sub-location slots. Blind by construction — this never reads or
     * returns expected_quantity_at_open. Blank/omitted quantities count as
     * zero. Only the 3 slots seeded at session-open time for this item can
     * be written — an unrecognized sub-location name is rejected rather
     * than silently creating a new, unbounded entry.
     *
     * $callerUserId is optional (and, when omitted, skips the ownership
     * check below) so every existing internal/test call site that doesn't
     * care about peer-to-peer authorization keeps working unchanged — the
     * real UI (CountSessionDetail) always passes auth()->id().
     *
     * @param array<string, float|string|null> $quantitiesBySubLocation
     */
    public function recordCount(CountSessionItem $item, array $quantitiesBySubLocation, ?int $callerUserId = null): CountSessionItem
    {
        $session = $item->session;

        if (!$session->isDraft()) {
            throw new \Exception('Counts can only be recorded while the session is still open.');
        }

        if ($callerUserId !== null && $session->isHandoverWithSuccessor()) {
            // Only the person actually doing the count may write it — the
            // incoming custodian's turn to weigh in is the separate review
            // phase (reviewProduct()), not this.
            $expectedCounterId = $session->isUnwitnessed()
                ? $session->incoming_user_id
                : $session->outgoing_user_id;

            if ($callerUserId !== $expectedCounterId) {
                throw new \Exception('Only the person doing the count can record it.');
            }
        }

        $validLocations = $item->subCounts()->pluck('sub_location')->all();

        $this->assertIntegerCounts($session, $quantitiesBySubLocation);

        foreach ($quantitiesBySubLocation as $subLocation => $quantity) {
            if (!in_array($subLocation, $validLocations, true)) {
                throw new \Exception("'{$subLocation}' is not a valid sub-location for this item.");
            }

            CountSessionSubCount::where('count_session_item_id', $item->id)
                ->where('sub_location', $subLocation)
                ->update(['quantity' => $quantity === null || $quantity === '' ? 0 : (float) $quantity]);
        }

        $item->update([
            'counted_quantity' => $item->subCounts()->sum('quantity'),
        ]);

        return $item->fresh();
    }

    /**
     * Bar drinks are whole units (bottles/cans) — a bar_handover count must
     * never accept a fractional figure. Kitchen ingredients (kg/litres)
     * genuinely need decimals, so this is scoped to session type, not
     * applied to every CountSession blanket — the underlying columns stay
     * decimal(10,2) for both.
     *
     * @param array<string, float|string|null> $quantitiesBySubLocation
     */
    private function assertIntegerCounts(CountSession $session, array $quantitiesBySubLocation): void
    {
        if ($session->type !== 'bar_handover') {
            return;
        }

        foreach ($quantitiesBySubLocation as $subLocation => $quantity) {
            if ($quantity === null || $quantity === '') {
                continue;
            }

            if (!is_numeric($quantity) || fmod((float) $quantity, 1.0) !== 0.0) {
                throw new \Exception("'{$subLocation}' must be a whole number — bar counts don't take fractions.");
            }
        }
    }

    public function confirmOutgoing(CountSession $session, int $userId): CountSession
    {
        if ($session->outgoing_user_id !== $userId) {
            throw new \Exception('Only the outgoing custodian can give this confirmation.');
        }

        $session->update(['confirmed_by_outgoing_at' => now()]);

        return $session->fresh();
    }

    public function confirmIncoming(CountSession $session, int $userId): CountSession
    {
        if ($session->incoming_user_id !== $userId) {
            throw new \Exception('Only the incoming custodian can give this confirmation.');
        }

        $session->update(['confirmed_by_incoming_at' => now()]);

        return $session->fresh();
    }

    /**
     * The outgoing custodian locks in their count as a declaration — from
     * here their figures can never be edited by the incoming custodian,
     * only amended by the outgoing themselves (amendDeclaration(), PIN-
     * signed) during dispute resolution. Declaration itself is PIN-signed
     * too, not just gated on the Livewire session's identity — the same
     * rigor as the amendment and the final dual-PIN seal. This is also
     * where the sales freeze begins: ending the outgoing's shift here (not
     * at final seal) means no bartender/chef shift exists until
     * sealAgreement() starts the incoming's, and OrderSplitter already
     * refuses bar/kitchen orders whenever no such shift is active.
     */
    public function declare(CountSession $session, string $outgoingPin, string $throttleKey): CountSession
    {
        if (!$session->isHandoverWithSuccessor()) {
            throw new \Exception('Only a handover count with a successor goes through declaration.');
        }

        if (!$session->isDraft()) {
            throw new \Exception('This count has already been declared.');
        }

        $outgoingUser = (new PinAuthService())->attempt($outgoingPin, $throttleKey);

        if (!$outgoingUser || $outgoingUser->id !== $session->outgoing_user_id) {
            throw new \Exception('That PIN does not match the outgoing custodian.');
        }

        return DB::transaction(function () use ($session) {
            $session->update([
                'confirmed_by_outgoing_at' => now(),
                'status' => 'declared',
            ]);

            (new BartenderChefShiftService())->beginHandoverFreeze($session->fresh());

            return $session->fresh();
        });
    }

    /**
     * PINs are the identity on kiosk surfaces — the account logged into the
     * device is irrelevant. incoming_user_id is only ever a guess made by
     * the outgoing custodian at session-open time (a dropdown pick, not a
     * verified identity); this is where the person who actually shows up to
     * review confirms who they are, by PIN, and that guess gets overwritten
     * with the PIN-verified identity. Review UI and the seal step both gate
     * on isIncomingBound() rather than trusting the pre-bind value.
     */
    public function bindIncomingCustodian(CountSession $session, string $incomingPin, string $throttleKey): CountSession
    {
        if (!$session->isHandoverWithSuccessor() || $session->isUnwitnessed()) {
            throw new \Exception('This session has no peer review phase to bind an incoming custodian to.');
        }

        if (!$session->isDeclared()) {
            throw new \Exception('This count has not been declared yet.');
        }

        if ($session->isIncomingBound()) {
            throw new \Exception('The incoming custodian has already confirmed their identity for this session.');
        }

        $role = self::ROLE_FOR_HANDOVER[$session->type];
        $user = (new PinAuthService())->attempt($incomingPin, $throttleKey);

        if (!$user) {
            throw new \Exception('That PIN does not match anyone.');
        }

        if (!$user->hasRole($role)) {
            throw new \Exception("Only a {$role} can review this count.");
        }

        if ($user->id === $session->outgoing_user_id) {
            throw new \Exception('The incoming custodian cannot be the same person as the outgoing custodian.');
        }

        $session->update([
            'incoming_user_id' => $user->id,
            'incoming_bound_at' => now(),
        ]);

        return $session->fresh();
    }

    /**
     * The incoming custodian's per-product review of a declared count:
     * either accept the outgoing's figure outright, or dispute it by
     * entering their own — which never overwrites the outgoing's numbers,
     * just records both figures side by side pending resolution. Callable
     * again to change a prior accept/dispute before the agreement is
     * sealed.
     *
     * @param array<string, float|string|null>|null $incomingQuantities
     */
    public function reviewProduct(CountSessionItem $item, int $incomingUserId, string $outcome, ?array $incomingQuantities = null): CountSessionItemReview
    {
        if (!in_array($outcome, ['accepted', 'disputed'], true)) {
            throw new \Exception('Invalid review outcome.');
        }

        $session = $item->session;

        if (!$session->isHandoverWithSuccessor() || $session->isUnwitnessed()) {
            throw new \Exception('This session has no peer review phase.');
        }

        if ($session->incoming_user_id !== $incomingUserId) {
            throw new \Exception('Only the incoming custodian can review this count.');
        }

        if (!$session->isDeclared()) {
            throw new \Exception('This count has not been declared yet.');
        }

        if ($outcome === 'disputed' && !$incomingQuantities) {
            throw new \Exception('A disputed product needs your own counted figures.');
        }

        if ($outcome === 'disputed') {
            $this->assertIntegerCounts($session, $incomingQuantities);
        }

        return CountSessionItemReview::updateOrCreate(
            ['count_session_item_id' => $item->id],
            [
                'reviewed_by' => $incomingUserId,
                'outcome' => $outcome,
                'incoming_quantities' => $outcome === 'disputed' ? $incomingQuantities : null,
                'resolved_at' => null,
            ]
        );
    }

    /**
     * The outgoing custodian's PIN-signed correction after the two of them
     * recount a disputed product together — an open amendment, they can
     * enter any figure, not just split the difference. Writing to their
     * own count_session_sub_counts rows again (LogsActivity on that model
     * captures the before/after) resolves the dispute back to 'accepted'.
     *
     * @param array<string, float|string|null> $newQuantities
     */
    public function amendDeclaration(CountSessionItem $item, string $outgoingPin, array $newQuantities, string $throttleKey): CountSessionItem
    {
        $session = $item->session;

        if (!$session->isHandoverWithSuccessor()) {
            throw new \Exception('Only a handover count with a successor can be amended this way.');
        }

        $review = $item->review;

        if (!$review || !$review->isDisputed()) {
            throw new \Exception('This product is not currently disputed.');
        }

        $outgoingUser = (new PinAuthService())->attempt($outgoingPin, $throttleKey);

        if (!$outgoingUser || $outgoingUser->id !== $session->outgoing_user_id) {
            throw new \Exception('That PIN does not match the outgoing custodian.');
        }

        $subCounts = $item->subCounts()->get()->keyBy('sub_location');

        foreach (array_keys($newQuantities) as $subLocation) {
            if (!$subCounts->has($subLocation)) {
                throw new \Exception("'{$subLocation}' is not a valid sub-location for this item.");
            }
        }

        $this->assertIntegerCounts($session, $newQuantities);

        return DB::transaction(function () use ($item, $newQuantities, $subCounts, $review) {
            foreach ($newQuantities as $subLocation => $quantity) {
                // Loaded instances, updated one at a time — not a mass
                // ::where()->update(), which bypasses Eloquent model events
                // and would silently skip LogsActivity's before/after trail,
                // the whole point of amending through this method.
                $subCounts[$subLocation]->update([
                    'quantity' => $quantity === null || $quantity === '' ? 0 : (float) $quantity,
                ]);
            }

            $item->update(['counted_quantity' => $item->subCounts()->sum('quantity')]);

            $review->update(['outcome' => 'accepted', 'resolved_at' => now()]);

            return $item->fresh();
        });
    }

    /**
     * The incoming custodian's explicit call, after a joint recount still
     * didn't settle a disputed product: their figure becomes the baseline
     * used at sealing, and a manager is notified — as a reader of the
     * flag, never a gate. The handover proceeds unblocked either way.
     */
    public function markUnresolved(CountSessionItem $item, int $incomingUserId): CountSessionItemReview
    {
        $session = $item->session;

        if ($session->incoming_user_id !== $incomingUserId) {
            throw new \Exception('Only the incoming custodian can mark this unresolved.');
        }

        $review = $item->review;

        if (!$review || !$review->isDisputed()) {
            throw new \Exception('This product is not currently disputed.');
        }

        $review->update(['outcome' => 'unresolved', 'resolved_at' => now()]);

        $this->notifyManagersOfUnresolvedDispute($item);

        return $review->fresh();
    }

    private function notifyManagersOfUnresolvedDispute(CountSessionItem $item): void
    {
        $managers = User::whereHas('roles', function ($q) {
            $q->whereIn('name', ['manager', 'admin', 'super_admin']);
        })->get();

        foreach ($managers as $manager) {
            Notification::make()
                ->title('Unresolved handover dispute')
                ->body("Count session #{$item->count_session_id}, {$item->itemName()}: the two custodians could not agree, so the incoming custodian's figure was used.")
                ->warning()
                ->sendToDatabase($manager);
        }
    }

    /**
     * The dual-PIN seal: both sides sign with their own PIN (or, on the
     * unwitnessed path, the witness signs in place of the absent outgoing)
     * and the handover closes atomically — variance computed against LIVE
     * stock exactly like submitForReview() does, shortfalls charged to
     * the accountable (outgoing) custodian, stock true-up recorded via
     * InventoryTransaction, and the incoming custodian's shift starts,
     * lifting the freeze beginHandoverFreeze() started at declaration.
     */
    public function sealAgreement(CountSession $session, string $firstPin, string $secondPin, string $throttleKey): CountSession
    {
        if (!$session->isHandoverWithSuccessor()) {
            throw new \Exception('Only a handover count with a successor is sealed this way.');
        }

        $isUnwitnessed = $session->isUnwitnessed();

        if ($isUnwitnessed) {
            if (!$session->isDraft()) {
                throw new \Exception('This unwitnessed count is not ready to be sealed.');
            }
        } else {
            if (!$session->isDeclared()) {
                throw new \Exception('This count must be declared before it can be sealed.');
            }

            if ($session->items()->whereDoesntHave('review')->exists()) {
                throw new \Exception('Every product must be reviewed before the agreement can be sealed.');
            }

            if ($session->items()->whereHas('review', fn ($q) => $q->where('outcome', 'disputed'))->exists()) {
                throw new \Exception('Every disputed product must be resolved or marked unresolved before sealing.');
            }
        }

        $pinAuth = new PinAuthService();

        $firstUser = $pinAuth->attempt($firstPin, "{$throttleKey}:first");

        if ($isUnwitnessed) {
            // A witness carries no responsibility for the counted numbers
            // (candidateWitnesses() deliberately isn't role-restricted), so
            // there's no pre-existing "expected" identity worth protecting —
            // whoever validly PIN-authenticates at this moment IS the
            // witness, resolved by lookup and bound here rather than
            // compared against session-open's unverified dropdown guess.
            if (!$firstUser) {
                throw new \Exception('That PIN does not match anyone.');
            }

            if ($firstUser->id === $session->incoming_user_id) {
                throw new \Exception('The witness cannot be the same person as the incoming custodian.');
            }
        } else {
            $outgoingName = $session->outgoingUser?->name ?? 'the outgoing custodian';

            if (!$firstUser || $firstUser->id !== $session->outgoing_user_id) {
                throw new \Exception("Outgoing signature: PIN does not match {$outgoingName}'s PIN.");
            }
        }

        if (!$isUnwitnessed && !$session->isIncomingBound()) {
            throw new \Exception('The incoming custodian must confirm their identity via PIN (at review start) before this can be sealed.');
        }

        $secondExpectedUserId = $session->incoming_user_id;
        $incomingName = $session->incomingUser?->name ?? 'the incoming custodian';

        $secondUser = $pinAuth->attempt($secondPin, "{$throttleKey}:second");

        if (!$secondUser || $secondUser->id !== $secondExpectedUserId) {
            throw new \Exception("Incoming signature: PIN does not match {$incomingName}'s PIN.");
        }

        return DB::transaction(function () use ($session, $isUnwitnessed, $firstUser, $secondUser) {
            if ($isUnwitnessed) {
                $session->update(['witness_user_id' => $firstUser->id]);
            }

            $totalShortageValue = 0.0;
            $totalOverageQuantity = 0.0;

            foreach ($session->items as $item) {
                $review = $item->review;

                $finalQuantity = ($review && $review->isUnresolved())
                    ? (float) array_sum($review->incoming_quantities ?? [])
                    : (float) ($item->counted_quantity ?? 0);

                $currentQty = $item->item_type === 'product'
                    ? (float) (InventoryItem::where('product_id', $item->product_id)->where('warehouse_id', $session->warehouse_id)->value('quantity') ?? 0)
                    : (float) (IngredientInventoryItem::where('ingredient_id', $item->ingredient_id)->where('warehouse_id', $session->warehouse_id)->value('quantity') ?? 0);

                $variance = $finalQuantity - $currentQty;

                // Frozen here, at seal time, from the exact same price source
                // chargeAccountability() always used — never recomputed from
                // a live price afterward, so a later price change can't alter
                // this session's historic naira figures.
                $unitPrice = $this->unitSellingPrice($item);
                $varianceValue = round($variance * $unitPrice, 2);

                $item->update([
                    'adjusted_expected_quantity' => $currentQty,
                    'counted_quantity' => $finalQuantity,
                    'variance' => $variance,
                    'unit_selling_price' => $unitPrice,
                    'variance_value' => $varianceValue,
                ]);

                if (abs($variance) > 0.0001) {
                    $this->trueUpStock($item, $variance, $secondUser->id);

                    if ($variance < 0) {
                        // No StaffDebt yet — the manager decides what happens
                        // to each shortage afterward (recount / debit / pend
                        // / write off) via the HandoverDiscrepancies queue.
                        // The dual-PIN seal never gates on that decision.
                        HandoverDiscrepancy::create([
                            'count_session_item_id' => $item->id,
                            'shortfall_quantity' => abs($variance),
                            'unit_price' => $unitPrice,
                            'naira_value' => abs($varianceValue),
                            'status' => 'pending_resolution',
                        ]);
                        $totalShortageValue += abs($varianceValue);
                        $item->update(['decision' => 'accountability']);
                    } else {
                        $totalOverageQuantity += $variance;
                        $item->update(['decision' => 'true_up']);
                    }
                }
            }

            $session->update([
                'status' => 'reviewed',
                'reviewed_at' => now(),
                'total_shortage_value' => $totalShortageValue,
                'total_overage_quantity' => $totalOverageQuantity,
            ]);

            (new BartenderChefShiftService())->completeHandoverBoundary($session->fresh());

            activity('count_session')
                ->performedOn($session)
                ->withProperties([
                    'sealed_by_first' => $firstUser->id,
                    'sealed_by_second' => $secondUser->id,
                    'unwitnessed' => $isUnwitnessed,
                ])
                ->log('Handover sealed with dual-PIN agreement');

            return $session->fresh();
        });
    }

    /**
     * Close counting and compute each item's adjusted-expected quantity
     * against LIVE stock right now — since nothing outside the transaction
     * pipeline can move stock, the current InventoryItem/IngredientInventoryItem
     * figure already equals "expected at open, adjusted for everything that
     * happened during counting."
     */
    public function submitForReview(CountSession $session): CountSession
    {
        if (!$session->isDraft()) {
            throw new \Exception('Only a session that is still counting can be submitted for review.');
        }

        if ($session->isHandover() && $session->outgoing_user_id && !$session->confirmed_by_outgoing_at) {
            throw new \Exception('Both the outgoing and incoming custodian must confirm the count before it can be submitted.');
        }

        if ($session->isHandover() && !$session->confirmed_by_incoming_at) {
            throw new \Exception('Both the outgoing and incoming custodian must confirm the count before it can be submitted.');
        }

        return DB::transaction(function () use ($session) {
            foreach ($session->items as $item) {
                $currentQty = $item->item_type === 'product'
                    ? (float) (InventoryItem::where('product_id', $item->product_id)->where('warehouse_id', $session->warehouse_id)->value('quantity') ?? 0)
                    : (float) (IngredientInventoryItem::where('ingredient_id', $item->ingredient_id)->where('warehouse_id', $session->warehouse_id)->value('quantity') ?? 0);

                $counted = (float) ($item->counted_quantity ?? 0);

                $item->update([
                    'adjusted_expected_quantity' => $currentQty,
                    'variance' => $counted - $currentQty,
                ]);
            }

            $session->update([
                'status' => 'pending_review',
                'submitted_for_review_at' => now(),
            ]);

            return $session->fresh();
        });
    }

    /**
     * Manager decision on one item's variance. Both 'true_up' and
     * 'accountability' correct the book to match the physical count;
     * 'accountability' additionally charges the accountable custodian at
     * valuation (selling price for products, last purchase price for
     * ingredients). 'ignored' leaves the book untouched.
     */
    public function reviewItem(CountSessionItem $item, int $reviewerId, string $decision, ?string $notes = null): CountSessionItem
    {
        if (!in_array($decision, ['true_up', 'accountability', 'ignored'], true)) {
            throw new \Exception('Invalid decision.');
        }

        if (!$item->session->isPendingReview()) {
            throw new \Exception('This session is not awaiting review.');
        }

        $variance = (float) ($item->variance ?? 0);

        return DB::transaction(function () use ($item, $reviewerId, $decision, $notes, $variance) {
            if ($decision !== 'ignored' && abs($variance) > 0.0001) {
                $this->trueUpStock($item, $variance, $reviewerId);
            }

            if ($decision === 'accountability' && $variance < 0) {
                $this->chargeAccountability($item, abs($variance), $reviewerId);
            }

            $item->update(['decision' => $decision, 'decision_notes' => $notes]);

            return $item->fresh();
        });
    }

    /**
     * Finalize the session once every non-zero-variance item has a
     * decision. For a handover, this is literally the shift boundary — the
     * outgoing custodian's shift ends and the incoming custodian's shift
     * starts in the same transaction, with no separate step to forget.
     */
    public function finalizeReview(CountSession $session, int $reviewedByUserId): CountSession
    {
        if (!$session->isPendingReview()) {
            throw new \Exception('Only a session pending review can be finalized.');
        }

        $undecided = $session->items()
            ->whereNull('decision')
            ->where(function ($q) {
                $q->where('variance', '>', 0.0001)->orWhere('variance', '<', -0.0001);
            })
            ->exists();

        if ($undecided) {
            throw new \Exception('Every item with a variance must have a decision before this session can be finalized.');
        }

        return DB::transaction(function () use ($session, $reviewedByUserId) {
            $session->update([
                'status' => 'reviewed',
                'reviewed_by' => $reviewedByUserId,
                'reviewed_at' => now(),
            ]);

            if ($session->isHandover()) {
                (new BartenderChefShiftService())->applyHandoverShiftBoundary($session->fresh());
            }

            return $session->fresh();
        });
    }

    private function trueUpStock(CountSessionItem $item, float $variance, int $reviewerId): void
    {
        $warehouseId = $item->session->warehouse_id;

        if ($item->item_type === 'product') {
            InventoryItem::updateOrCreate(
                ['product_id' => $item->product_id, 'warehouse_id' => $warehouseId],
                []
            )->update(['quantity' => $item->counted_quantity]);

            InventoryTransaction::create([
                'product_id' => $item->product_id,
                'warehouse_id' => $warehouseId,
                'type' => 'adjustment',
                'quantity' => abs($variance),
                'reference' => "count_session:{$item->count_session_id}",
                'user_id' => $reviewerId,
            ]);
        } else {
            IngredientInventoryItem::updateOrCreate(
                ['ingredient_id' => $item->ingredient_id, 'warehouse_id' => $warehouseId],
                []
            )->update(['quantity' => $item->counted_quantity]);

            IngredientTransaction::create([
                'ingredient_id' => $item->ingredient_id,
                'warehouse_id' => $warehouseId,
                'type' => 'adjustment',
                'quantity' => abs($variance),
                'reference' => "count_session:{$item->count_session_id}",
                'user_id' => $reviewerId,
            ]);
        }
    }

    private function chargeAccountability(CountSessionItem $item, float $shortfallQuantity, int $reviewerId): void
    {
        $accountableUserId = $item->session->accountableUserId();

        if (!$accountableUserId) {
            throw new \Exception('There is no accountable user recorded on this session to charge.');
        }

        $unitValue = $this->unitSellingPrice($item);

        StaffDebt::create([
            'user_id' => $accountableUserId,
            'amount' => round($shortfallQuantity * $unitValue, 2),
            'reason' => 'count_session_shortfall',
            'notes' => "Count session #{$item->count_session_id}, item #{$item->id} ({$item->itemName()}): "
                . "{$shortfallQuantity} short at " . number_format($unitValue, 2) . ' per unit.',
            'created_by' => $reviewerId,
        ]);
    }

    /**
     * The valuation path shared by the handover seal snapshot and the
     * main_store_stocktake manager-charge path: selling price for products,
     * last purchase cost for ingredients (no dedicated "selling price"
     * column exists on Ingredient).
     */
    private function unitSellingPrice(CountSessionItem $item): float
    {
        return $item->item_type === 'product'
            ? (float) (Product::find($item->product_id)?->price ?? 0)
            : $this->lastPurchasePrice($item->ingredient_id);
    }

    private function lastPurchasePrice(int $ingredientId): float
    {
        return (float) (IngredientTransaction::where('ingredient_id', $ingredientId)
            ->where('type', 'purchase')
            ->whereNotNull('cost_per_unit')
            ->latest('id')
            ->value('cost_per_unit') ?? 0);
    }

    /**
     * Manager orders a witnessed verification recount of a flagged product
     * — counted by the current on-duty custodian for that role, witnessed
     * by any PIN holder (mirrors the unwitnessed handover's witness
     * co-sign: resolved by PIN lookup, not compared against a pre-picked
     * identity). Adjusts live stock to the new figure via InventoryTransaction/
     * IngredientTransaction (never a direct write), recomputes the variance
     * against the session's frozen adjusted_expected_quantity, and always
     * returns the line to pending_resolution — the manager still has to
     * pick an outcome afterward, even if the recount cleared the shortfall.
     * The original snapshot line (unit_selling_price, variance_value on the
     * CountSessionItem) is never mutated; only this discrepancy's own
     * shortfall_quantity/naira_value move.
     */
    public function recordVerificationRecount(
        HandoverDiscrepancy $discrepancy,
        float $newQuantity,
        string $counterPin,
        string $witnessPin,
        int $orderedByUserId,
        string $throttleKey,
    ): HandoverDiscrepancy {
        if (!$discrepancy->isOpen()) {
            throw new \Exception('This discrepancy has already been resolved.');
        }

        $item = $discrepancy->item;
        $session = $item->session;
        $role = self::ROLE_FOR_HANDOVER[$session->type] ?? null;

        $pinAuth = new PinAuthService();

        $counter = $pinAuth->attempt($counterPin, "{$throttleKey}:counter");

        if (!$counter || ($role && !$counter->hasRole($role))) {
            throw new \Exception("That PIN does not match a {$role}.");
        }

        $witness = $pinAuth->attempt($witnessPin, "{$throttleKey}:witness");

        if (!$witness) {
            throw new \Exception('That PIN does not match anyone.');
        }

        if ($witness->id === $counter->id) {
            throw new \Exception('The witness cannot be the same person as the counter.');
        }

        return DB::transaction(function () use ($discrepancy, $item, $session, $newQuantity, $counter, $witness, $orderedByUserId) {
            $warehouseId = $session->warehouse_id;
            $isProduct = $item->item_type === 'product';

            $currentQty = $isProduct
                ? (float) (InventoryItem::where('product_id', $item->product_id)->where('warehouse_id', $warehouseId)->value('quantity') ?? 0)
                : (float) (IngredientInventoryItem::where('ingredient_id', $item->ingredient_id)->where('warehouse_id', $warehouseId)->value('quantity') ?? 0);

            $delta = $newQuantity - $currentQty;
            $inventoryTransactionId = null;
            $ingredientTransactionId = null;

            if (abs($delta) > 0.0001) {
                if ($isProduct) {
                    InventoryItem::updateOrCreate(
                        ['product_id' => $item->product_id, 'warehouse_id' => $warehouseId],
                        []
                    )->update(['quantity' => $newQuantity]);

                    $inventoryTransactionId = InventoryTransaction::create([
                        'product_id' => $item->product_id,
                        'warehouse_id' => $warehouseId,
                        'type' => 'adjustment',
                        'quantity' => abs($delta),
                        'reference' => "handover_discrepancy:{$discrepancy->id}:recount",
                        'user_id' => $orderedByUserId,
                    ])->id;
                } else {
                    IngredientInventoryItem::updateOrCreate(
                        ['ingredient_id' => $item->ingredient_id, 'warehouse_id' => $warehouseId],
                        []
                    )->update(['quantity' => $newQuantity]);

                    $ingredientTransactionId = IngredientTransaction::create([
                        'ingredient_id' => $item->ingredient_id,
                        'warehouse_id' => $warehouseId,
                        'type' => 'adjustment',
                        'quantity' => abs($delta),
                        'reference' => "handover_discrepancy:{$discrepancy->id}:recount",
                        'user_id' => $orderedByUserId,
                    ])->id;
                }
            }

            $recomputedVariance = $newQuantity - (float) $item->adjusted_expected_quantity;
            $newShortfall = max(0, -$recomputedVariance);

            HandoverDiscrepancyRecount::create([
                'handover_discrepancy_id' => $discrepancy->id,
                'new_quantity' => $newQuantity,
                'recomputed_variance' => $recomputedVariance,
                'counted_by' => $counter->id,
                'witnessed_by' => $witness->id,
                'inventory_transaction_id' => $inventoryTransactionId,
                'ingredient_transaction_id' => $ingredientTransactionId,
                'ordered_by' => $orderedByUserId,
            ]);

            $discrepancy->update([
                'shortfall_quantity' => $newShortfall,
                'naira_value' => round($newShortfall * (float) $discrepancy->unit_price, 2),
                'status' => 'pending_resolution',
                'investigation_note' => null,
            ]);

            return $discrepancy->fresh();
        });
    }

    public function debitDiscrepancy(HandoverDiscrepancy $discrepancy, int $managerId): HandoverDiscrepancy
    {
        if (!$discrepancy->isOpen()) {
            throw new \Exception('This discrepancy has already been resolved.');
        }

        return DB::transaction(function () use ($discrepancy, $managerId) {
            $item = $discrepancy->item;
            $accountableUserId = $item->session->accountableUserId();

            if (!$accountableUserId) {
                throw new \Exception('There is no accountable user recorded on this session to charge.');
            }

            $debt = StaffDebt::create([
                'user_id' => $accountableUserId,
                'amount' => $discrepancy->naira_value,
                'reason' => 'count_session_shortfall',
                'notes' => "Count session #{$item->count_session_id}, item #{$item->id} ({$item->itemName()}): "
                    . "{$discrepancy->shortfall_quantity} short at " . number_format((float) $discrepancy->unit_price, 2) . ' per unit.',
                'created_by' => $managerId,
            ]);

            $discrepancy->update([
                'status' => 'debited',
                'staff_debt_id' => $debt->id,
                'resolved_by' => $managerId,
                'resolved_at' => now(),
            ]);

            return $discrepancy->fresh();
        });
    }

    public function pendDiscrepancyInvestigation(HandoverDiscrepancy $discrepancy, string $note, int $managerId): HandoverDiscrepancy
    {
        if (!$discrepancy->isOpen()) {
            throw new \Exception('This discrepancy has already been resolved.');
        }

        if (trim($note) === '') {
            throw new \Exception('An investigation note is required.');
        }

        $discrepancy->update([
            'status' => 'pending_investigation',
            'investigation_note' => $note,
        ]);

        return $discrepancy->fresh();
    }

    public function writeOffDiscrepancy(HandoverDiscrepancy $discrepancy, string $reason, int $managerId): HandoverDiscrepancy
    {
        if (!$discrepancy->isOpen()) {
            throw new \Exception('This discrepancy has already been resolved.');
        }

        if (trim($reason) === '') {
            throw new \Exception('A written reason is required to resolve without a debit.');
        }

        $discrepancy->update([
            'status' => 'written_off',
            'resolution_note' => $reason,
            'resolved_by' => $managerId,
            'resolved_at' => now(),
        ]);

        return $discrepancy->fresh();
    }

    /**
     * Bulk-resolve any collection of discrepancies with a single ruling —
     * used both for "every remaining line on this session" and for an
     * arbitrary manager-selected set in the discrepancy queue table.
     * Already-resolved lines in the set are skipped, not fatal.
     *
     * @param \Illuminate\Support\Collection<int, HandoverDiscrepancy> $discrepancies
     * @return array{debited: int, failed: int}
     */
    public function bulkDebitRemaining($discrepancies, int $managerId): array
    {
        $debited = 0;
        $failed = 0;

        foreach ($discrepancies as $discrepancy) {
            try {
                $this->debitDiscrepancy($discrepancy, $managerId);
                $debited++;
            } catch (\Exception $e) {
                $failed++;
            }
        }

        return ['debited' => $debited, 'failed' => $failed];
    }

    /**
     * @param \Illuminate\Support\Collection<int, HandoverDiscrepancy> $discrepancies
     * @return array{written_off: int, failed: int}
     */
    public function bulkWriteOffRemaining($discrepancies, string $reason, int $managerId): array
    {
        $writtenOff = 0;
        $failed = 0;

        foreach ($discrepancies as $discrepancy) {
            try {
                $this->writeOffDiscrepancy($discrepancy, $reason, $managerId);
                $writtenOff++;
            } catch (\Exception $e) {
                $failed++;
            }
        }

        return ['written_off' => $writtenOff, 'failed' => $failed];
    }
}
