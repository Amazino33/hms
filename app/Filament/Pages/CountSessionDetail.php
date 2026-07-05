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

class CountSessionDetail extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.count-session-detail';

    protected static ?string $slug = 'count-session-detail';

    public static function canAccess(): bool
    {
        // Gated by the same permission as the Count Sessions list this page
        // is always reached from.
        return PermissionService::canAccessPage(CountSessions::class);
    }

    public ?CountSession $session = null;

    public array $subLocationInputs = [];

    public array $reviewDecisions = [];

    public array $reviewNotes = [];

    public function mount(?int $session_id = null): void
    {
        $this->session = CountSession::with(['items.subCounts', 'items.product', 'items.ingredient', 'warehouse', 'outgoingUser', 'incomingUser'])
            ->find($session_id);

        if (!$this->session) {
            redirect('/admin/count-sessions');
            return;
        }
    }

    public function getTitle(): string
    {
        return 'Count Session #' . ($this->session?->id ?? '');
    }

    protected function refreshSession(): void
    {
        $this->session = $this->session->fresh(['items.subCounts', 'items.product', 'items.ingredient', 'warehouse', 'outgoingUser', 'incomingUser']);
    }

    public function recordCount(int $itemId): void
    {
        $subLocation = trim($this->subLocationInputs[$itemId]['location'] ?? '');
        $quantity = $this->subLocationInputs[$itemId]['qty'] ?? null;

        if ($subLocation === '' || $quantity === null || $quantity === '') {
            Notification::make()->title('Enter a sub-location and quantity first')->warning()->send();
            return;
        }

        $item = $this->session->items->firstWhere('id', $itemId);

        try {
            (new CountSessionService())->recordCount($item, $subLocation, (float) $quantity);
            $this->refreshSession();
            $this->subLocationInputs[$itemId] = ['location' => '', 'qty' => ''];

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
