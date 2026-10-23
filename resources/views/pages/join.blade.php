<?php

use App\Models\StaffApplication;
use App\Services\StaffApplicationService;
use App\Services\StaffApplicationSettings;
use App\Support\NigerianBanks;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The public staff application form (/join). Open only while the super
 * admin has applications switched on. Submitting creates a Pending
 * application — never a user account, never a login.
 */
new #[Layout('layouts::join')] class extends Component {
    use WithFileUploads;

    /** Submissions allowed per network address per hour. Generous because
     *  staff applying on the venue's own Wi-Fi all share one address. */
    public const PER_IP_PER_HOUR = 10;

    public const PER_EMAIL_PER_HOUR = 3;

    public string $full_name = '';
    public string $phone = '';
    public string $email = '';
    public string $address = '';
    public string $password = '';
    public string $password_confirmation = '';

    public string $next_of_kin_name = '';
    public string $next_of_kin_phone = '';
    public string $next_of_kin_relationship = '';

    public string $bank_name = '';
    public string $account_number = '';
    public string $account_name = '';

    public string $guarantor_name = '';
    public string $guarantor_phone = '';
    public string $guarantor_address = '';
    public string $guarantor_relationship = '';
    public string $guarantor_occupation = '';

    public string $id_type = '';
    public string $id_number = '';

    public $photo = null;
    public $id_document = null;
    public $guarantor_form = null;

    /** Hidden from people; a bot filling every field fills this too. */
    public string $website = '';

    public ?string $submittedReference = null;

    public function isOpen(): bool
    {
        return StaffApplicationSettings::isOpen();
    }

    protected function rules(): array
    {
        $phone = ['required', 'string', 'regex:/^\+?[0-9 ]{10,16}$/'];

        return [
            'full_name' => ['required', 'string', 'min:3', 'max:120'],
            'phone' => $phone,
            'email' => ['required', 'email', 'max:190'],
            'address' => ['required', 'string', 'min:5', 'max:500'],
            'password' => ['required', 'string', 'min:8', 'max:100', 'confirmed'],
            'next_of_kin_name' => ['required', 'string', 'max:120'],
            'next_of_kin_phone' => $phone,
            'next_of_kin_relationship' => ['required', 'string', 'max:60'],
            'bank_name' => ['required', Rule::in(array_keys(NigerianBanks::all()))],
            'account_number' => ['required', 'digits:10'],
            'account_name' => ['required', 'string', 'min:3', 'max:120'],
            'guarantor_name' => ['required', 'string', 'max:120'],
            'guarantor_phone' => $phone,
            'guarantor_address' => ['required', 'string', 'min:5', 'max:500'],
            'guarantor_relationship' => ['required', 'string', 'max:60'],
            'guarantor_occupation' => ['required', 'string', 'max:120'],
            'id_type' => ['required', Rule::in(array_keys(StaffApplication::ID_TYPES))],
            'id_number' => ['required', 'string', 'max:40'],
            'photo' => ['required', 'file', 'max:8192', 'mimes:jpg,jpeg,png,webp,heic,heif'],
            'id_document' => ['required', 'file', 'max:8192', 'mimes:jpg,jpeg,png,webp,heic,heif,pdf'],
            'guarantor_form' => ['required', 'file', 'max:8192', 'mimes:jpg,jpeg,png,webp,heic,heif,pdf'],
        ];
    }

    protected function messages(): array
    {
        return [
            'phone.regex' => 'Enter a phone number, e.g. 0803 123 4567.',
            'next_of_kin_phone.regex' => 'Enter a phone number, e.g. 0803 123 4567.',
            'guarantor_phone.regex' => 'Enter a phone number, e.g. 0803 123 4567.',
            'account_number.digits' => 'Account numbers have exactly 10 digits.',
            '*.max' => 'That is too long.',
            'photo.max' => 'That photo is over 8 MB.',
            'id_document.max' => 'That file is over 8 MB.',
            'guarantor_form.max' => 'That file is over 8 MB.',
        ];
    }

    public function submit(StaffApplicationService $service): void
    {
        if (! $this->isOpen()) {
            $this->addError('form', 'Applications are currently closed.');

            return;
        }

        $ipKey = 'staff-apply-ip:'.request()->ip();

        if (RateLimiter::tooManyAttempts($ipKey, self::PER_IP_PER_HOUR)) {
            $this->addError('form', 'Too many applications from this network. Please try again in an hour.');

            return;
        }

        RateLimiter::hit($ipKey, 3600);

        // A bot filled the hidden field: look successful, store nothing.
        if ($this->website !== '') {
            $this->submittedReference = 'APP-'.strtoupper(\Illuminate\Support\Str::random(6));

            return;
        }

        $data = $this->validate();

        $emailKey = 'staff-apply-email:'.strtolower(trim($this->email));

        if (RateLimiter::tooManyAttempts($emailKey, self::PER_EMAIL_PER_HOUR)) {
            $this->addError('email', 'Too many attempts with this email. Please try again in an hour.');

            return;
        }

        RateLimiter::hit($emailKey, 3600);

        try {
            $application = $service->submit($data, [
                'photo' => $this->photo,
                'id_document' => $this->id_document,
                'guarantor_form' => $this->guarantor_form,
            ]);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }

        $this->submittedReference = $application->reference;
        $this->resetExcept('submittedReference');
    }
}; ?>

<div class="flex flex-col gap-6">
    @if ($submittedReference)
        <div class="rounded-xl border border-green-200 bg-white p-6 text-center shadow-sm">
            <h2 class="text-lg font-semibold">Application received</h2>
            <p class="mt-2 text-sm text-zinc-600">Thank you. Your reference is</p>
            <p class="mt-1 text-2xl font-bold tracking-wide">{{ $submittedReference }}</p>
            <p class="mt-4 text-sm text-zinc-600">The manager will review it and contact you. Once you are approved you will sign in with the email and password you just chose.</p>
        </div>
    @elseif (! $this->isOpen())
        <div class="rounded-xl border border-zinc-200 bg-white p-6 text-center shadow-sm">
            <h2 class="text-lg font-semibold">Applications are currently closed</h2>
            <p class="mt-2 text-sm text-zinc-600">We are not taking staff applications right now. Please check back later.</p>
        </div>
    @else
        <div class="text-center">
            <h2 class="text-lg font-semibold">Staff application</h2>
            <p class="mt-1 text-sm text-zinc-600">Fill in every section. Your details are kept private and only seen by the manager.</p>
        </div>

        <form wire:submit="submit" class="flex flex-col gap-6" novalidate>
            {{-- Honeypot: hidden from people, irresistible to bots. --}}
            <div aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;">
                <label for="website">Website</label>
                <input id="website" type="text" wire:model="website" tabindex="-1" autocomplete="off">
            </div>

            @error('form')
                <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">{{ $message }}</div>
            @enderror

            <section class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-white p-5 shadow-sm">
                <h3 class="font-semibold">About you</h3>
                <flux:input wire:model="full_name" label="Full name" autocomplete="name" required />
                <flux:input wire:model="phone" label="Phone number" type="tel" autocomplete="tel" placeholder="0803 123 4567" required />
                <flux:input wire:model="email" label="Email" type="email" autocomplete="email" required />
                <flux:textarea wire:model="address" label="Home address" rows="2" required />

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium" for="photo">Passport photo</label>
                    <p class="text-xs text-zinc-500">A clear photo of your face. You can take it now with your phone.</p>
                    <input id="photo" type="file" wire:model="photo" accept="image/*" capture="user" class="text-sm">
                    <div wire:loading wire:target="photo" class="text-xs text-zinc-500">Uploading…</div>
                    @error('photo') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </section>

            <section class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-white p-5 shadow-sm">
                <h3 class="font-semibold">Your login</h3>
                <p class="text-xs text-zinc-500">Choose a password you will remember. You will use it with your email to sign in once you are approved. Nobody else can see it.</p>
                <flux:input wire:model="password" label="Password" type="password" autocomplete="new-password" required />
                <flux:input wire:model="password_confirmation" label="Confirm password" type="password" autocomplete="new-password" required />
            </section>

            <section class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-white p-5 shadow-sm">
                <h3 class="font-semibold">Next of kin</h3>
                <flux:input wire:model="next_of_kin_name" label="Name" required />
                <flux:input wire:model="next_of_kin_phone" label="Phone number" type="tel" required />
                <flux:input wire:model="next_of_kin_relationship" label="Relationship to you" placeholder="e.g. Mother, Brother" required />
            </section>

            <section class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-white p-5 shadow-sm">
                <h3 class="font-semibold">Bank details</h3>
                <flux:select wire:model="bank_name" label="Bank" placeholder="Choose your bank" required>
                    @foreach (\App\Support\NigerianBanks::all() as $key => $label)
                        <flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="account_number" label="Account number" inputmode="numeric" maxlength="10" required />
                <flux:input wire:model="account_name" label="Account name" required />
            </section>

            <section class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-white p-5 shadow-sm">
                <h3 class="font-semibold">Guarantor</h3>
                <p class="text-xs text-zinc-500">Someone who knows you well and will vouch for you.</p>
                <flux:input wire:model="guarantor_name" label="Name" required />
                <flux:input wire:model="guarantor_phone" label="Phone number" type="tel" required />
                <flux:textarea wire:model="guarantor_address" label="Address" rows="2" required />
                <flux:input wire:model="guarantor_relationship" label="Relationship to you" required />
                <flux:input wire:model="guarantor_occupation" label="Occupation" required />

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium" for="guarantor_form">Signed guarantor form</label>
                    <p class="text-xs text-zinc-500">A clear photo or PDF of the form your guarantor signed.</p>
                    <input id="guarantor_form" type="file" wire:model="guarantor_form" accept="image/*,application/pdf" class="text-sm">
                    <div wire:loading wire:target="guarantor_form" class="text-xs text-zinc-500">Uploading…</div>
                    @error('guarantor_form') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </section>

            <section class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-white p-5 shadow-sm">
                <h3 class="font-semibold">Means of ID</h3>
                <flux:select wire:model="id_type" label="ID type" placeholder="Choose" required>
                    @foreach (\App\Models\StaffApplication::ID_TYPES as $key => $label)
                        <flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="id_number" label="ID number" required />

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium" for="id_document">Photo of your ID</label>
                    <p class="text-xs text-zinc-500">A clear photo or PDF of the ID card itself.</p>
                    <input id="id_document" type="file" wire:model="id_document" accept="image/*,application/pdf" class="text-sm">
                    <div wire:loading wire:target="id_document" class="text-xs text-zinc-500">Uploading…</div>
                    @error('id_document') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </section>

            <flux:button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled" wire:target="submit,photo,id_document,guarantor_form">
                <span wire:loading.remove wire:target="submit">Submit application</span>
                <span wire:loading wire:target="submit">Sending…</span>
            </flux:button>
        </form>
    @endif
</div>
