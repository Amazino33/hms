<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\Auth;

new class extends Component {
    public const INACTIVITY_TIMEOUT_SECONDS = 75;

    public $table;

    public function mount($table): void
    {
        $this->table = $table;
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
