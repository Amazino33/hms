<?php

use App\Services\PinAuthService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    public string $pin = '';
    public string $pin_confirmation = '';

    public function updatePin(): void
    {
        $this->validate([
            'pin' => 'required|digits:4',
            'pin_confirmation' => 'required|same:pin',
        ]);

        try {
            (new PinAuthService())->setPin(Auth::user(), $this->pin);
        } catch (\InvalidArgumentException $e) {
            $this->addError('pin', $e->getMessage());
            $this->reset('pin', 'pin_confirmation');

            return;
        }

        $this->reset('pin', 'pin_confirmation');
        $this->dispatch('pin-updated');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('PIN Settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Set your kiosk PIN')" :subheading="__('This 4-digit PIN identifies you on the bar kiosk and unlocks your trusted phone — it never grants access to this admin panel.')">
        <form method="POST" wire:submit="updatePin" class="mt-6 space-y-6">
            <flux:input
                wire:model="pin"
                :label="__('New 4-digit PIN')"
                type="password"
                inputmode="numeric"
                maxlength="4"
                required
            />
            <flux:input
                wire:model="pin_confirmation"
                :label="__('Confirm PIN')"
                type="password"
                inputmode="numeric"
                maxlength="4"
                required
            />

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full">
                        {{ __('Save') }}
                    </flux:button>
                </div>

                <x-action-message class="me-3" on="pin-updated">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        </form>
    </x-pages::settings.layout>
</section>
