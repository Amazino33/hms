<?php

namespace App\Filament\Pages;

use App\Models\CountSession;
use App\Models\Shift;
use App\Services\BartenderChefShiftService;
use App\Services\CountSessionService;
use App\Services\PermissionService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Computed;

class CountSessionDetail extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.count-session-detail';

    protected static ?string $slug = 'count-session-detail';

    public static function canAccess(): bool
    {
        // Checks its OWN permission, not CountSessions' — this page is now
        // reached two ways: via the admin Count Sessions list (manager/
        // storekeeper), and directly via MyCount's redirect (bartender/
        // chef), who deliberately don't have CountSessions access anymore.
        // Piggybacking on CountSessions' permission would 403 the second
        // path for exactly the roles MyCount was built for.
        return PermissionService::canAccessPage(self::class);
    }

    public ?int $countSessionId = null;

    public array $subLocationInputs = [];

    public array $reviewDecisions = [];

    public array $reviewNotes = [];

    /**
     * catchCandidates()'s search pool, computed once in mount() rather than
     * on every request. It's only ever consumed inside the wire:ignore'd
     * counting flow's x-data — sent to the client once and never re-read
     * from the server afterward (addCatchItem() updates the client's own
     * copy directly) — so recomputing its whereNotIn() query on every
     * single Next/edit round trip was pure wasted latency.
     *
     * @var array<int, array{id: int, name: string}>
     */
    public array $catchCandidatesCache = [];

    /**
     * Deliberately a #[Computed] property, not a public one: public
     * properties are part of Livewire's wire:snapshot and get shipped to
     * the browser on every request, regardless of what the Blade template
     * chooses to render. During blind counting, expected_quantity_at_open
     * / adjusted_expected_quantity must never reach the client at all —
     * not just stay unprinted — so the whole CountSessionItem model (and
     * the expected quantities it carries) is kept server-side only.
     */
    #[Computed]
    public function session(): ?CountSession
    {
        return CountSession::with(['items.subCounts', 'items.product', 'items.ingredient', 'items.review', 'items.discrepancy', 'warehouse', 'outgoingUser', 'incomingUser', 'witnessUser'])
            ->find($this->countSessionId);
    }

    public function mount(?int $session_id = null): void
    {
        // $session_id (the mount parameter) is only reliably populated when
        // a test injects it directly via Livewire::test(['session_id' =>
        // ...]) — on a real HTTP GET, Filament's page routing doesn't
        // forward ?session_id= into mount() the way a bare Livewire full-
        // page route would, so it silently arrived null and every real
        // visitor bounced straight back to the list. Reading the query
        // string directly here is what actually works for a real request.
        $queryValue = request()->integer('session_id');
        $this->countSessionId = $session_id ?? ($queryValue > 0 ? $queryValue : null);

        if (!$this->session) {
            redirect('/admin/count-sessions');
            return;
        }

        $this->prefillSubLocationInputs();
        $this->catchCandidatesCache = $this->computeCatchCandidates();
    }

    /**
     * Pre-fills the counting inputs from whatever's already persisted on
     * each item's fixed sub-location slots — the CountSessionSubCount rows
     * themselves ARE the draft, so a reloaded page shows the count exactly
     * as it was left, not blank inputs.
     */
    protected function prefillSubLocationInputs(): void
    {
        if (!$this->session || $this->session->status !== 'counting') {
            return;
        }

        foreach ($this->session->items as $item) {
            $this->subLocationInputs[$item->id] = $item->subCounts
                ->pluck('quantity', 'sub_location')
                ->all();
        }
    }

    /**
     * The one-product-at-a-time counting flow needs its item list available
     * client-side up front (so moving between products is instant, no
     * round trip) — but only a safe subset: name and sub-location labels,
     * plus whatever's already been counted (the counter's own persisted
     * entries). Never expected_quantity_at_open / adjusted_expected_quantity
     * — that's the same guarantee session() protects by staying
     * #[Computed] instead of a public property; a plain PHP method call
     * from the Blade view (not a public property) keeps this out of the
     * wire:snapshot the same way.
     *
     * @return array<int, array{id: int, name: string, subLocations: array<int, string>, values: array<string, string>}>
     */
    public function safeCountItems(): array
    {
        if (!$this->session || $this->session->status !== 'counting') {
            return [];
        }

        // Pack-conversion entry (crate/pack chips + live base-unit math) is
        // only offered on a solo count — bar/kitchen handovers count in
        // whole bottles/portions already and never had this. units_per_
        // purchase_unit/purchase_unit_name are plain product attributes,
        // not expected-quantity data, so sending them is no blindness leak.
        $offerPackEntry = $this->isSoloCount();

        return $this->session->items->map(fn ($item) => [
            'id' => $item->id,
            'name' => $item->itemName(),
            'subLocations' => $item->subCounts->pluck('sub_location')->all(),
            'values' => $item->subCounts->pluck('quantity', 'sub_location')
                ->map(fn ($q) => $q > 0 ? $this->formatQuantity($q) : '')
                ->all(),
            'unitsPerPack' => ($offerPackEntry && $item->item_type === 'product')
                ? $item->product?->units_per_purchase_unit
                : null,
            'packLabel' => ($offerPackEntry && $item->item_type === 'product')
                ? $item->product?->purchase_unit_name
                : null,
        ])->values()->all();
    }

    /**
     * safeCountItems() plus a zero-nudge flag per row — the declaration
     * summary is already skip-zero filtered (only products with stock > 0
     * at all), so an all-zero row is usually a miss, not a real count.
     * Deliberately a PHP method, not an inline @php block in the Blade
     * view: a @php...@endphp block placed immediately before a @foreach
     * broke Livewire's compiled wire:key wrapping at that nesting depth,
     * and the single-line @php(...) form choked on this expression's
     * nested parens/brackets — computing it here sidesteps both.
     *
     * @return array<int, array{id: int, name: string, subLocations: array<int, string>, values: array<string, string>, isAllZero: bool}>
     */
    public function declarationSummaryItems(): array
    {
        return collect($this->safeCountItems())->map(fn ($item) => $item + [
            'isAllZero' => collect($item['values'])->every(fn ($v) => $v === '' || (float) $v === 0.0),
        ])->all();
    }

    /**
     * Whether this session was actually opened in 'in_stock_only' mode —
     * read from the session's own frozen count_scope column, never the
     * live companies.handover_count_scope setting, so an admin flipping
     * the setting mid-session can't make the catch step appear/disappear
     * out from under whoever is already counting. In 'all' mode the catch
     * step is redundant (nothing was skipped from the list) and hidden.
     */
    public function catchStepEnabled(): bool
    {
        return $this->session?->count_scope === 'in_stock_only';
    }

    /**
     * The skip-zero catch step's search pool: everything NOT already on
     * the frozen count list, for whichever catalog this session type
     * counts (products for a bar handover, ingredients for kitchen).
     * Sent to the client once — filtering by typed text happens locally,
     * same client-first pattern as every other kiosk search in this app.
     *
     * Just an accessor over catchCandidatesCache (computed once, in
     * mount()) — kept as its own method since existing tests and the
     * catch-step's own docs call it by this name.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function catchCandidates(): array
    {
        return $this->catchCandidatesCache;
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function computeCatchCandidates(): array
    {
        if (!$this->session || $this->session->status !== 'counting' || !$this->catchStepEnabled()) {
            return [];
        }

        $column = $this->session->type === 'kitchen_handover' ? 'ingredient_id' : 'product_id';
        $existingIds = $this->session->items->pluck($column)->filter()->all();

        if ($this->session->type === 'kitchen_handover') {
            return \App\Models\Ingredient::whereNotIn('id', $existingIds)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($i) => ['id' => $i->id, 'name' => $i->name])
                ->all();
        }

        return \App\Models\Product::whereNotIn('id', $existingIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])
            ->all();
    }

    /**
     * Returns the new item in the same shape safeCountItems() produces, so
     * the JS can splice it straight into the frozen `items` array — the
     * counting screen is wire:ignore'd (frozen after first render), so
     * there's no server re-render to pick this up from otherwise.
     *
     * @return array{id: int, name: string, subLocations: array<int, string>, values: array<string, string>}|null
     */
    public function addCatchItem(string $itemType, int $itemId): ?array
    {
        if (!$this->catchStepEnabled()) {
            Notification::make()->title('Could not add item')->body('This session is not using the in-stock-only catch step.')->danger()->send();

            return null;
        }

        try {
            $item = (new CountSessionService())->addCatchItem($this->session, $itemType, $itemId, auth()->id());
            $this->refreshSession();
            $this->prefillSubLocationInputs();

            return [
                'id' => $item->id,
                'name' => $item->itemName(),
                'subLocations' => $item->subCounts->pluck('sub_location')->all(),
                'values' => $item->subCounts->pluck('quantity', 'sub_location')->map(fn () => '')->all(),
            ];
        } catch (\Exception $e) {
            Notification::make()->title('Could not add item')->body($e->getMessage())->danger()->send();

            return null;
        }
    }

    /**
     * Bar drinks are whole units — never show "24.00" for a bar_handover
     * count, even though the underlying column is decimal(10,2) to serve
     * kitchen ingredients (kg/litres) too, which keep their decimals.
     */
    public function formatQuantity(mixed $quantity): string
    {
        if ($this->session?->type === 'bar_handover') {
            return (string) (int) round((float) $quantity);
        }

        return (string) $quantity;
    }

    public function getTitle(): string
    {
        return 'Count Session #' . ($this->session?->id ?? '');
    }

    protected function refreshSession(): void
    {
        unset($this->session);
    }

    /**
     * Returns whether the save actually succeeded — the client-side
     * saveCurrent()/advance() flow in the counting screen awaits this
     * return value (not just the request round-trip) before ever
     * advancing currentIndex. Previously this always returned void, so a
     * caught exception here (e.g. an ownership-check failure) still
     * resolved the JS promise as "success" and the counter silently moved
     * on with nothing written — the notification was the only signal, and
     * on a kiosk screen nobody's watching for a toast mid-count.
     *
     * $quantities is taken directly as an argument rather than read back
     * off $this->subLocationInputs — the JS side used to call
     * $wire.set('subLocationInputs...') and then $wire.call('recordCount'),
     * two sequential round trips for what's really one save. Passing the
     * values straight through collapses that to a single trip, which
     * matters most on the slow/mobile connections this gate exists for in
     * the first place. subLocationInputs is still kept in sync below, since
     * it's what prefillSubLocationInputs() reads back on a page reload.
     *
     * @param array<string, float|string|null> $quantities
     */
    public function recordCount(int $itemId, array $quantities): bool
    {
        $item = $this->session->items->firstWhere('id', $itemId);

        if (!$item) {
            return false;
        }

        $this->subLocationInputs[$itemId] = $quantities;

        try {
            (new CountSessionService())->recordCount($item, $quantities, auth()->id());
            $this->refreshSession();
            $this->prefillSubLocationInputs();

            return true;
        } catch (\Exception $e) {
            Notification::make()->title('Could not record count')->body($e->getMessage())->danger()->send();

            return false;
        }
    }

    /**
     * Scoped per session + per logged-in user, so a wrong PIN typed while
     * declaring doesn't lock out the same person trying to seal later —
     * each PIN-entry point on this page throttles independently.
     */
    protected function pinThrottleKey(string $step): string
    {
        return "count_session:{$this->countSessionId}:{$step}:" . auth()->id();
    }

    /**
     * The outgoing custodian's own figures, one product per page — reused
     * as-is for the declare-confirmation summary since it's already blind
     * (name/sub-locations/counted values only, never expected quantities).
     */
    public function declare(string $pin): void
    {
        try {
            (new CountSessionService())->declare($this->session, $pin, $this->pinThrottleKey('declare'));
            $this->refreshSession();
            Notification::make()->title('Count declared')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not declare')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * The incoming custodian's per-product review data: the outgoing's
     * declared figures (not the system's expected quantity — this is what
     * was physically counted, safe to show the person reviewing it) plus
     * whatever review outcome already exists for it.
     *
     * @return array<int, array{id: int, name: string, subLocations: array<int, string>, declaredValues: array<string, string>, outcome: ?string, incomingValues: array<string, mixed>}>
     */
    public function safeReviewItems(): array
    {
        if (!$this->session || $this->session->status !== 'declared') {
            return [];
        }

        return $this->session->items->map(fn ($item) => [
            'id' => $item->id,
            'name' => $item->itemName(),
            'subLocations' => $item->subCounts->pluck('sub_location')->all(),
            'declaredValues' => $item->subCounts->pluck('quantity', 'sub_location')
                ->map(fn ($q) => $this->formatQuantity($q))->all(),
            'outcome' => $item->review?->outcome,
            'incomingValues' => $item->review?->incoming_quantities ?? [],
        ])->values()->all();
    }

    /**
     * The incoming custodian's own PIN-authentication to begin review —
     * resolves identity by PIN lookup and (re)binds incoming_user_id to
     * whoever actually typed it, overwriting the outgoing custodian's
     * unverified guess from session-open. The kiosk's logged-in account
     * plays no part in this decision.
     */
    public function bindIncomingReview(string $pin): void
    {
        try {
            (new CountSessionService())->bindIncomingCustodian($this->session, $pin, $this->pinThrottleKey('bind'));
            $this->refreshSession();
            Notification::make()->title('Identity confirmed — you can review now')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not confirm identity')->body($e->getMessage())->danger()->send();
        }
    }

    public function reviewAccept(int $itemId): void
    {
        if (!$this->iAmReviewer()) {
            Notification::make()->title('Only the incoming custodian can review this count')->danger()->send();

            return;
        }

        $item = $this->session->items->firstWhere('id', $itemId);

        if (!$item) {
            return;
        }

        try {
            (new CountSessionService())->reviewProduct($item, $this->session->incoming_user_id, 'accepted');
            $this->refreshSession();
        } catch (\Exception $e) {
            Notification::make()->title('Could not accept')->body($e->getMessage())->danger()->send();
        }
    }

    public function reviewDispute(int $itemId, array $quantities): void
    {
        if (!$this->iAmReviewer()) {
            Notification::make()->title('Only the incoming custodian can review this count')->danger()->send();

            return;
        }

        $item = $this->session->items->firstWhere('id', $itemId);

        if (!$item) {
            return;
        }

        try {
            (new CountSessionService())->reviewProduct($item, $this->session->incoming_user_id, 'disputed', $quantities);
            $this->refreshSession();
        } catch (\Exception $e) {
            Notification::make()->title('Could not record dispute')->body($e->getMessage())->danger()->send();
        }
    }

    public function amendDeclaration(int $itemId, string $pin, array $quantities): void
    {
        $item = $this->session->items->firstWhere('id', $itemId);

        if (!$item) {
            return;
        }

        try {
            (new CountSessionService())->amendDeclaration($item, $pin, $quantities, $this->pinThrottleKey('amend'));
            $this->refreshSession();
            Notification::make()->title('Declaration amended')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not amend')->body($e->getMessage())->danger()->send();
        }
    }

    public function markItemUnresolved(int $itemId): void
    {
        if (!$this->iAmReviewer()) {
            Notification::make()->title('Only the incoming custodian can mark this unresolved')->danger()->send();

            return;
        }

        $item = $this->session->items->firstWhere('id', $itemId);

        if (!$item) {
            return;
        }

        try {
            (new CountSessionService())->markUnresolved($item, $this->session->incoming_user_id);
            $this->refreshSession();
            Notification::make()->title('Marked unresolved — a supervisor has been notified')->warning()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not mark unresolved')->body($e->getMessage())->danger()->send();
        }
    }

    public function isHandoverWithSuccessor(): bool
    {
        return $this->session?->isHandoverWithSuccessor() ?? false;
    }

    public function isUnwitnessedSession(): bool
    {
        return $this->session?->isUnwitnessed() ?? false;
    }

    /**
     * Whether the current user is the one authorized to record counts
     * right now — the outgoing custodian normally, or the incoming
     * custodian whenever there is no outgoing physically present to do it
     * (the unwitnessed path, or a solo opening count with no outgoing
     * custodian at all). Mirrors the same check
     * CountSessionService::recordCount() enforces server-side; this is
     * only for deciding what to render.
     */
    public function iAmCounter(): bool
    {
        $session = $this->session;

        if (!$session) {
            return false;
        }

        // A solo session (no outgoing AND no incoming — a store stocktake)
        // has nobody to fall back to but whoever opened it; the
        // outgoing/incoming branch below would otherwise compare against
        // incoming_user_id, which is never set for this type at all.
        if (!$session->isHandover()) {
            return $session->accountableUserId() === auth()->id();
        }

        return ($session->isUnwitnessed() || $session->outgoing_user_id === null)
            ? $session->incoming_user_id === auth()->id()
            : $session->outgoing_user_id === auth()->id();
    }

    /**
     * A solo count has no successor and no witness — main_store_stocktake
     * today, but written against isHandover() rather than the literal type
     * so any future non-handover session type gets the same single-PIN
     * submit path for free.
     */
    public function isSoloCount(): bool
    {
        return $this->session ? !$this->session->isHandover() : false;
    }

    /**
     * Returns whether the submit actually succeeded, same reasoning as
     * sealAgreement()'s bool return — the PIN screen needs to know whether
     * to reset itself.
     */
    public function submitSoloCount(string $pin): bool
    {
        try {
            (new CountSessionService())->submitSoloCount($this->session, $pin, $this->pinThrottleKey('solo-submit'));
            $this->refreshSession();
            Notification::make()->title('Count submitted')->success()->send();

            return true;
        } catch (\Exception $e) {
            Notification::make()->title('Could not submit')->body($e->getMessage())->danger()->send();

            return false;
        }
    }

    /**
     * True once the incoming custodian has PIN-confirmed their identity —
     * deliberately not an auth()->id() === incoming_user_id comparison for
     * the incoming side: the whole point of bindIncomingCustodian() is that
     * the kiosk's logged-in account is irrelevant to who is allowed to
     * review. isIncomingBound() is a session-wide flag though, not tied to
     * any one device or login — so without also excluding the outgoing
     * custodian's own account here, the outgoing could load this same page
     * on their own login (not the shared kiosk) and see/use the peer
     * review Accept/Dispute actions on their own declared count, once
     * anyone anywhere had bound as incoming.
     */
    public function iAmReviewer(): bool
    {
        return (bool) $this->session?->isIncomingBound() && !$this->iAmOutgoing();
    }

    /**
     * True when a declared, non-unwitnessed session is waiting for its
     * incoming custodian to PIN-authenticate before review can begin.
     */
    public function needsIncomingBinding(): bool
    {
        $session = $this->session;

        return $session
            && $session->isDeclared()
            && $session->isHandoverWithSuccessor()
            && !$session->isUnwitnessed()
            && !$session->isIncomingBound();
    }

    public function iAmOutgoing(): bool
    {
        return $this->session && $this->session->outgoing_user_id === auth()->id();
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\CountSessionItem>
     */
    public function disputedItems(): \Illuminate\Support\Collection
    {
        if (!$this->session) {
            return collect();
        }

        return $this->session->items->filter(fn ($i) => $i->review?->outcome === 'disputed');
    }

    /**
     * True once every product has an explicit review outcome (or, on the
     * unwitnessed path, once counting is simply done — there's no review
     * stage at all) — this is what unlocks the dual-PIN seal screen.
     */
    public function readyToSeal(): bool
    {
        $session = $this->session;

        if (!$session || !$session->isHandoverWithSuccessor()) {
            return false;
        }

        if ($session->isUnwitnessed()) {
            return $session->isDraft();
        }

        return $session->isDeclared()
            && $session->isIncomingBound()
            && !$session->items()->whereDoesntHave('review')->exists()
            && !$session->items()->whereHas('review', fn ($q) => $q->where('outcome', 'disputed'))->exists();
    }

    /**
     * Returns whether the seal actually succeeded — the dual-PIN screen's
     * JS uses this to reset itself back to the first PIN entry on failure.
     * Previously this always returned void, so a wrong first PIN (the
     * outgoing/witness signature, captured and held client-side before the
     * second PIN is even typed) surfaced its error only after the second
     * PIN was entered, with no way back except a full page reload — the
     * screen just kept re-submitting the same bad first PIN forever.
     */
    public function sealAgreement(string $firstPin, string $secondPin): bool
    {
        try {
            (new CountSessionService())->sealAgreement($this->session, $firstPin, $secondPin, $this->pinThrottleKey('seal'));
            $this->refreshSession();
            Notification::make()->title('Handover sealed')->success()->send();

            return true;
        } catch (\Exception $e) {
            Notification::make()->title('Could not seal')->body($e->getMessage())->danger()->send();

            return false;
        }
    }

    public function confirmOutgoing(): void
    {
        try {
            (new CountSessionService())->confirmOutgoing($this->session, auth()->id());
            $this->refreshSession();
            Notification::make()->title('Outgoing confirmation recorded')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not confirm')->body($e->getMessage())->danger()->send();
        }
    }

    public function confirmIncoming(): void
    {
        try {
            (new CountSessionService())->confirmIncoming($this->session, auth()->id());
            $this->refreshSession();
            Notification::make()->title('Incoming confirmation recorded')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not confirm')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * Self-service for the exact mistake that motivated this: someone
     * picked the wrong person (or even themselves) and now the session is
     * stuck blocking their own MyCount page. Anyone party to the session
     * can clear it themselves before it's gone anywhere real; a manager
     * can clear anyone's.
     */
    public function canCancelSession(): bool
    {
        $session = $this->session;

        if (!$session || !$session->isCancellable()) {
            return false;
        }

        $userId = auth()->id();

        if (in_array($userId, [$session->opened_by, $session->outgoing_user_id, $session->incoming_user_id, $session->witness_user_id], true)) {
            return true;
        }

        return auth()->user()->hasAnyRole(['manager', 'admin', 'super_admin']);
    }

    public function cancelSession(?string $reason = null): void
    {
        if (!$this->canCancelSession()) {
            Notification::make()->title('You are not able to cancel this session')->danger()->send();
            return;
        }

        try {
            (new CountSessionService())->cancelSession($this->session, auth()->id(), $reason);
            Notification::make()->title('Session cancelled')->success()->send();
            $this->redirect('/admin/my-count');
        } catch (\Exception $e) {
            Notification::make()->title('Could not cancel')->body($e->getMessage())->danger()->send();
        }
    }

    public function submitForReview(): void
    {
        try {
            (new CountSessionService())->submitForReview($this->session);
            $this->refreshSession();
            Notification::make()->title('Submitted for supervisor review')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not submit')->body($e->getMessage())->danger()->send();
        }
    }

    public function decideItem(int $itemId): void
    {
        $decision = $this->reviewDecisions[$itemId] ?? null;
        $notes = $this->reviewNotes[$itemId] ?? null;

        if (!$decision) {
            Notification::make()->title('Choose a decision first')->warning()->send();
            return;
        }

        $item = $this->session->items->firstWhere('id', $itemId);

        try {
            (new CountSessionService())->reviewItem($item, auth()->id(), $decision, $notes);
            $this->refreshSession();
            Notification::make()->title('Decision recorded')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not record decision')->body($e->getMessage())->danger()->send();
        }
    }

    public function finalizeReview(): void
    {
        try {
            (new CountSessionService())->finalizeReview($this->session, auth()->id());
            $this->refreshSession();
            Notification::make()->title('Session finalized')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not finalize')->body($e->getMessage())->danger()->send();
        }
    }

    private const ROLE_FOR_SESSION_TYPE = [
        'bar_handover' => 'bartender',
        'kitchen_handover' => 'chef',
    ];

    /**
     * True only for a reviewed, solo opening count (no outgoing custodian —
     * there was nobody to hand over from) that this user opened/is named on,
     * and only before a shift has already been started from it.
     */
    public function canStartMyShift(): bool
    {
        $session = $this->session;

        if (!$session->isReviewed() || $session->outgoing_user_id !== null) {
            return false;
        }

        $role = self::ROLE_FOR_SESSION_TYPE[$session->type] ?? null;

        if (!$role) {
            return false;
        }

        $userId = auth()->id();

        if ($session->incoming_user_id !== $userId && $session->opened_by !== $userId) {
            return false;
        }

        return !Shift::where('opening_count_session_id', $session->id)->exists();
    }

    public function startMyShift(): void
    {
        $session = $this->session;
        $role = self::ROLE_FOR_SESSION_TYPE[$session->type] ?? null;

        if (!$role) {
            return;
        }

        try {
            (new BartenderChefShiftService())->startOpeningShift(auth()->user(), $role, $session);
            $this->refreshSession();
            Notification::make()->title('Shift started')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not start shift')->body($e->getMessage())->danger()->send();
        }
    }
}
