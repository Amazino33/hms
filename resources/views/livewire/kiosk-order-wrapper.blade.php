<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\Auth;
use Filament\Notifications\Livewire\Notifications;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\VerticalAlignment;

new class extends Component {
    public const INACTIVITY_TIMEOUT_SECONDS = 75;

    public $table;

    public function mount($table): void
    {
        $this->table = $table;

        // Top-right is easy to miss on a kiosk touchscreen the waiter isn't
        // staring at edge-on — top-center sits right above the product grid,
        // where their eyes already are.
        Notifications::alignment(Alignment::Center);
        Notifications::verticalAlignment(VerticalAlignment::Start);
    }

    /**
     * One login = one order interaction. Whether the waiter sent items to
     * the kitchen or took a payment, that's the end of this kiosk session.
     */
    #[On('order-completed')]
    public function onOrderCompleted(): void
    {
        $this->returnToTableGrid();
    }

    /**
     * Manual "Lock" button on the order screen — same effect as the
     * inactivity timeout firing early, for a waiter stepping away on
     * purpose (e.g. handing the kiosk to a customer) instead of waiting
     * out the 75s timer.
     */
    #[On('lock-requested')]
    public function onLockRequested(): void
    {
        $this->returnToTableGrid();
    }

    /**
     * Inactivity timeout fired from the client. Nothing needs to be
     * explicitly "discarded" — any unsent cart only ever existed in this
     * component's in-memory Alpine/Livewire state, never persisted, so
     * simply leaving the page IS the discard.
     */
    public function discardAndReturn(): void
    {
        $this->returnToTableGrid();
    }

    private function returnToTableGrid(): void
    {
        $routeName = session('kiosk_device_id') ? 'kiosk.home' : 'staff.home';
        Auth::guard('staff_pin')->logout();
        $this->redirect(route($routeName), navigate: false);
    }
}; ?>

<div
    x-data="{
        timer: null,
        resetTimer() {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => $wire.discardAndReturn(), {{ self::INACTIVITY_TIMEOUT_SECONDS * 1000 }});
        }
    }"
    x-init="resetTimer()"
    @click.window="resetTimer()"
    @keydown.window="resetTimer()"
    @touchstart.window="resetTimer()"
>
    <livewire:pos :table_id="$table" />
</div>
