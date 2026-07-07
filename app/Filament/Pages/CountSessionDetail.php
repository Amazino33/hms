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
        return CountSession::with(['items.subCounts', 'items.product', 'items.ingredient', 'warehouse', 'outgoingUser', 'incomingUser'])
            ->find($this->countSessionId);
    }

    public function mount(?int $session_id = null): void
    {
        $this->countSessionId = $session_id;

        if (!$this->session) {
            redirect('/admin/count-sessions');
            return;
        }

        $this->prefillSubLocationInputs();
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

    public function getTitle(): string
    {
        return 'Count Session #' . ($this->session?->id ?? '');
    }

    protected function refreshSession(): void
    {
        unset($this->session);
    }

    public function recordCount(int $itemId): void
    {
        $item = $this->session->items->firstWhere('id', $itemId);

        if (!$item) {
            return;
        }

        // subLocationInputs.{itemId} is keyed by the item's 3 fixed
        // sub-location labels (e.g. Fridge/Floor/Shelf) — blank entries are
        // treated as zero by the service, not rejected.
        $quantities = $this->subLocationInputs[$itemId] ?? [];

        try {
            (new CountSessionService())->recordCount($item, $quantities);
            $this->refreshSession();
            $this->prefillSubLocationInputs();

            Notification::make()->title('Count recorded')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not record count')->body($e->getMessage())->danger()->send();
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

    public function submitForReview(): void
    {
        try {
            (new CountSessionService())->submitForReview($this->session);
            $this->refreshSession();
            Notification::make()->title('Submitted for manager review')->success()->send();
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
