<?php

use Livewire\Component;
use App\Models\Table;
use App\Services\PinAuthService;
use App\Exceptions\PinLockedException;
use Illuminate\Support\Facades\Auth;

new class extends Component {
    public ?int $selectedTableId = null;
    public string $selectedTableName = '';
    public string $pin = '';
    public ?string $errorMessage = null;
    public ?int $lockedUntilTimestamp = null;

    public function getTablesProperty()
    {
        return Table::orderBy('name')->get();
    }

    public function selectTable(int $tableId, string $tableName): void
    {
        $this->selectedTableId = $tableId;
        $this->selectedTableName = $tableName;
        $this->pin = '';
        $this->errorMessage = null;
        $this->lockedUntilTimestamp = null;
    }

    public function closePinPad(): void
    {
        $this->selectedTableId = null;
        $this->pin = '';
        $this->errorMessage = null;
    }

    public function pressDigit(string $digit): void
    {
        if (strlen($this->pin) >= PinAuthService::PIN_LENGTH) {
            return;
        }

        $this->pin .= $digit;

        if (strlen($this->pin) === PinAuthService::PIN_LENGTH) {
            $this->submitPin();
        }
    }

    public function backspace(): void
    {
        $this->pin = substr($this->pin, 0, -1);
    }

    /**
     * Scoped to whichever device actually made the attempt — a shared
     * kiosk terminal, or a specific trusted personal phone — never a
     * single global bucket, which would let one person's failures lock out
     * everyone else.
     */
    protected function throttleKey(): string
    {
        $kioskDeviceId = session('kiosk_device_id');
        if ($kioskDeviceId) {
            return "kiosk:{$kioskDeviceId}";
        }

        $trustedUserId = session('trusted_device_user_id');
        if ($trustedUserId) {
            return "trusted:{$trustedUserId}";
        }

        return 'unscoped:' . request()->ip();
    }

    public function submitPin(): void
    {
        $service = new PinAuthService();

        try {
            $user = $service->attempt($this->pin, $this->throttleKey());
        } catch (PinLockedException $e) {
            $this->lockedUntilTimestamp = $e->lockedUntilTimestamp;
            $this->errorMessage = $e->getMessage();
            $this->pin = '';
            return;
        }

        if (!$user) {
            $this->errorMessage = 'Incorrect PIN.';
            $this->pin = '';
            return;
        }

        // On a personal phone, the device is already bound to one specific
        // person — even a correct PIN belonging to someone ELSE must not
        // work here, or a lost/stolen phone would grant access as whoever's
        // PIN happens to be guessed, not just its actual owner.
        $trustedUserId = session('trusted_device_user_id');
        if ($trustedUserId && (int) $trustedUserId !== $user->id) {
            $this->errorMessage = 'This PIN does not belong to this device\'s owner.';
            $this->pin = '';
            return;
        }

        Auth::guard('staff_pin')->login($user);

        $routeName = session('kiosk_device_id') ? 'kiosk.order' : 'staff.order';
        $this->redirect(route($routeName, ['table' => $this->selectedTableId]), navigate: false);
    }
}; ?>

<div class="min-h-screen bg-gray-900 p-6">
    <h1 class="text-2xl font-bold text-white mb-6">Select a Table</h1>

    <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 gap-4">
        @foreach ($this->tables as $table)
            <button wire:click="selectTable({{ $table->id }}, '{{ $table->name }}')"
                class="aspect-square rounded-xl flex flex-col items-center justify-center font-bold text-lg
                    {{ $table->status === 'available' ? 'bg-green-600 text-white' : 'bg-amber-600 text-white' }}">
                {{ $table->name }}
                <span class="text-xs font-normal mt-1">{{ ucfirst($table->status) }}</span>
            </button>
        @endforeach
    </div>

    @if ($selectedTableId)
        <div class="fixed inset-0 bg-black/70 flex items-center justify-center z-50" wire:click.self="closePinPad">
            <div class="bg-white rounded-2xl p-6 w-full max-w-xs">
                <h2 class="text-lg font-bold text-gray-900 mb-1">{{ $selectedTableName }}</h2>
                <p class="text-sm text-gray-500 mb-4">Enter your 4-digit PIN</p>

                <div class="flex justify-center gap-2 mb-4">
                    @for ($i = 0; $i < 4; $i++)
                        <div class="w-4 h-4 rounded-full border-2 border-gray-400 {{ $i < strlen($pin) ? 'bg-gray-800' : '' }}"></div>
                    @endfor
                </div>

                @if ($errorMessage)
                    <p class="text-center text-red-600 text-sm font-medium mb-3">{{ $errorMessage }}</p>
                @endif

                <div class="grid grid-cols-3 gap-3">
                    @foreach (['1','2','3','4','5','6','7','8','9'] as $digit)
                        <button wire:click="pressDigit('{{ $digit }}')" class="py-4 bg-gray-100 rounded-lg text-xl font-bold">{{ $digit }}</button>
                    @endforeach
                    <button wire:click="closePinPad" class="py-4 bg-gray-200 rounded-lg text-sm font-bold">Cancel</button>
                    <button wire:click="pressDigit('0')" class="py-4 bg-gray-100 rounded-lg text-xl font-bold">0</button>
                    <button wire:click="backspace" class="py-4 bg-gray-200 rounded-lg text-sm font-bold">&larr;</button>
                </div>
            </div>
        </div>
    @endif
</div>
