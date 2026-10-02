<?php

namespace App\Livewire;

use App\Models\OwnerTakeNote;
use App\Models\Shift;
use Filament\Notifications\Notification;
use Livewire\Component;

class ShiftManager extends Component
{
    public $currentShift;

    public $shiftDuration;

    public $showModal = false;

    public $showDeclarationModal = false;

    public $showOwnerTakeModal = false;

    public $isProcessing = false;

    public bool $ready = false;

    public $declaredCash = 0;

    public $declaredPos = 0;

    public $ownerTakeAmount = null;

    public $ownerTakeDescription = '';

    /**
     * D3: guest tables blocking this waiter's end of shift — each still has
     * guest drinks waiting at the bar. [['session_id' => int, 'table' => string]]
     */
    public array $guestHandoverTables = [];

    public ?int $handoverSessionId = null;

    public ?int $handoverToUserId = null;

    protected $listeners = [
        'open-shift-modal' => 'openModal',
        'close-modal-safely' => 'closeModalSafely',
    ];

    public function mount()
    {
        // Do NOT load shift data here — wait for wire:init to call load()
        // This makes the component render instantly without hitting the DB
    }

    public function load(): void
    {
        $this->ready = true;
        $this->loadCurrentShift();
    }

    /**
     * Bartender/chef shifts only ever start through a reviewed opening
     * count or a declared handover — the generic Start Shift button below
     * is swapped for a redirect to My Handover Count for these two roles,
     * mirroring the existing End Shift swap for the same reason.
     */
    public function isBartenderOrChef(): bool
    {
        return auth()->check() && auth()->user()->hasAnyRole(['bartender', 'chef']);
    }

    /**
     * Same reasoning as isBartenderOrChef() above: receptionist shifts
     * track a cash float this generic control has no field for at all
     * (ReceptionistShiftService), so both Start and End are swapped for a
     * redirect to the dedicated Receptionist Shift page instead.
     */
    public function isReceptionist(): bool
    {
        return auth()->check() && auth()->user()->hasRole('receptionist');
    }

    public function loadCurrentShift()
    {
        // Skip if modal is open and we're processing to avoid hydration conflicts
        if ($this->showModal && $this->isProcessing) {
            return;
        }

        if (! auth()->check()) {
            $this->currentShift = null;
            $this->shiftDuration = null;

            return;
        }

        $this->currentShift = auth()->user()->currentShift();
        if ($this->currentShift && $this->currentShift->started_at) {
            // Calculate elapsed time (always positive)
            $seconds = $this->currentShift->started_at->diffInSeconds(now());
            $minutes = round($seconds / 60);

            if ($minutes < 60) {
                $this->shiftDuration = $minutes.'m';
            } else {
                $hours = floor($minutes / 60);
                $remainingMinutes = $minutes % 60;
                if ($remainingMinutes > 0) {
                    $this->shiftDuration = $hours.'h '.$remainingMinutes.'m';
                } else {
                    $this->shiftDuration = $hours.'h';
                }
            }
        } else {
            $this->shiftDuration = null;
        }
    }

    public function startShift()
    {
        if ($this->isProcessing || ! auth()->check()) {
            return;
        }

        $this->isProcessing = true;

        try {
            $shift = auth()->user()->startShift();
            $this->loadCurrentShift();

            Notification::make()->title('Shift Started')->success()->send();

            // Signal Alpine to close the modal instantly
            $this->dispatch('shift-started');

            // Close modal after successful shift start
            $this->showModal = false;

        } catch (\Exception $e) {
            Notification::make()->title('Error starting shift: '.$e->getMessage())->danger()->persistent()->send();
        } finally {
            $this->isProcessing = false;
        }
    }

    /**
     * Bartenders/chefs don't handle cash — there's nothing for them to
     * declare on the cash/POS modal, and User::endShift() throws for them
     * regardless. Called from the "End Shift" button's own wire:click for
     * these two roles (the button is plain Alpine — @click="showDeclarationModal
     * = true" — for everyone else, which is why gating this in PHP alone
     * previously did nothing: nothing called into Livewire for bartender/
     * chef to begin with).
     */
    public function goToHandoverCount()
    {
        $this->redirect('/admin/my-count');
    }

    public function goToReceptionistShift()
    {
        $this->redirect('/admin/receptionist-shift');
    }

    public function cancelDeclaration()
    {
        $this->showDeclarationModal = false;
        $this->declaredCash = 0;
        $this->declaredPos = 0;
    }

    public function cancelOwnerTake()
    {
        $this->showOwnerTakeModal = false;
        $this->ownerTakeAmount = null;
        $this->ownerTakeDescription = '';
    }

    /**
     * Just a note against the shift — no status/approve-reject workflow.
     * The cashier sees it during settlement and uses their own judgment
     * (the existing manual Staff Debt form) to decide what to record; the
     * CEO gets read-only visibility in /ceo. Deliberately doesn't touch
     * stock, cash totals, or anything else — recording it never blocks or
     * changes ending the shift afterward.
     */
    public function recordOwnerTake($ownerTakeAmount = null, $ownerTakeDescription = '')
    {
        if ($this->isProcessing || ! auth()->check() || ! $this->currentShift) {
            return;
        }

        if (trim((string) $ownerTakeDescription) === '') {
            Notification::make()->title('Add a short note about what was taken')->danger()->persistent()->send();

            return;
        }

        OwnerTakeNote::create([
            'shift_id' => $this->currentShift->id,
            'recorded_by' => auth()->id(),
            'amount' => $ownerTakeAmount !== null && $ownerTakeAmount !== '' ? (float) $ownerTakeAmount : null,
            'description' => trim((string) $ownerTakeDescription),
        ]);

        Notification::make()->title('Noted — the cashier will see this when settling your shift')->success()->send();

        $this->dispatch('owner-take-recorded');
        $this->showOwnerTakeModal = false;
    }

    public function confirmShiftEnd($declaredCash = 0, $declaredPos = 0)
    {
        if ($this->isProcessing || ! auth()->check()) {
            return;
        }

        // Validate amounts
        if ($declaredCash < 0 || $declaredPos < 0) {
            Notification::make()->title('Cash and POS amounts cannot be negative')->danger()->persistent()->send();

            return;
        }

        $this->isProcessing = true;

        try {
            $shift = auth()->user()->endShift();

            // Update shift with declared amounts
            if ($shift) {
                $shift->update([
                    'declared_cash' => $declaredCash,
                    'declared_pos' => $declaredPos,
                ]);
            }

            $this->loadCurrentShift();

            Notification::make()->title('Shift Ended Successfully')->success()->send();

            // Signal Alpine to close both modals instantly
            $this->dispatch('shift-ended');

            // Keep PHP state in sync
            $this->showModal = false;
            $this->showDeclarationModal = false;
            $this->declaredCash = 0;
            $this->declaredPos = 0;

        } catch (\Exception $e) {
            $this->loadGuestHandoverTables();
            Notification::make()->title('Error ending shift: '.$e->getMessage())->danger()->persistent()->send();
        } finally {
            $this->isProcessing = false;
        }
    }

    public function loadGuestHandoverTables(): void
    {
        $this->guestHandoverTables = auth()->user()
            ? \App\Services\Guest\GuestSessionHandoverService::blockingSessions(auth()->user())
                ->map(fn ($s) => ['session_id' => $s->id, 'table' => $s->table?->name ?? 'Table'])
                ->values()->all()
            : [];
    }

    /** Waiters who could take a table over: on an active waiter shift, not me. */
    public function handoverCandidates(): array
    {
        return Shift::query()->activeNonStale('waiter')->with('user:id,name')
            ->where('user_id', '!=', auth()->id())
            ->get()
            ->pluck('user.name', 'user.id')
            ->all();
    }

    public function closeGuestHandover(): void
    {
        $this->guestHandoverTables = [];
        $this->handoverSessionId = null;
        $this->handoverToUserId = null;
    }

    /**
     * Hands one guest table to another on-shift waiter, who confirms with
     * their own PIN — the PIN must be the chosen waiter's.
     */
    public function handoverGuestTable(?string $pin = null): void
    {
        $session = \App\Models\GuestTableSession::find($this->handoverSessionId);
        $to = $this->handoverToUserId ? \App\Models\User::find($this->handoverToUserId) : null;

        if (! $session || ! $to) {
            \App\Services\UserFeedback::blocked('Pick a table and a waiter', 'Choose which table to hand over and who is taking it.');

            return;
        }

        try {
            $pinOwner = (new \App\Services\PinAuthService)->attempt((string) $pin, 'handover:'.auth()->id());
        } catch (\App\Exceptions\PinLockedException $e) {
            \App\Services\UserFeedback::blocked('Too many wrong PINs', $e->getMessage());

            return;
        }

        if (! $pinOwner || $pinOwner->id !== $to->id) {
            \App\Services\UserFeedback::blocked('Wrong PIN', "{$to->name} must enter their own PIN to take the table.");

            return;
        }

        try {
            (new \App\Services\Guest\GuestSessionHandoverService)->handover($session, auth()->user(), $to);
        } catch (\Exception $e) {
            \App\Services\UserFeedback::blocked('Table not handed over', $e->getMessage());

            return;
        }

        \App\Services\UserFeedback::succeeded("Handed to {$to->name}", 'Their guest drinks are now theirs to deliver.');
        $this->handoverSessionId = null;
        $this->handoverToUserId = null;
        $this->loadGuestHandoverTables();
    }

    public function openModal()
    {
        if ($this->isProcessing) {
            // Determine which operation is in progress
            $operation = $this->currentShift ? 'stopping' : 'starting';
            Notification::make()
                ->title("Please wait, shift is {$operation}...")
                ->warning()
                ->duration(3000)
                ->send();

            return;
        }

        $this->showModal = true;
    }

    public function closeModal()
    {
        // Add a small delay to ensure any pending operations complete
        $this->dispatch('close-modal-safely', ['delay' => 100]);
    }

    public function closeModalSafely()
    {
        $this->showModal = false;
        $this->loadCurrentShift(); // Ensure fresh data
    }

    public function updatedShowModal($value)
    {
        // When modal opens, ensure we have fresh data
        if ($value) {
            $this->loadCurrentShift();
        }
    }

    public function dehydrate()
    {
        // Ensure clean state before serialization
        $this->isProcessing = false;
    }

    public function render()
    {
        return view('livewire.shift-manager');
    }
}
