<x-filament-panels::page>
    @if(!$this->myRole())
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 text-center text-gray-500 dark:text-gray-400">
            Your account isn't set up as a bartender or chef, so there's nothing to count here.
        </div>
    @elseif($this->myOpenSession)
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-amber-300 dark:border-amber-700 p-6">
            <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-2">You already have a count in progress</h3>
            <p class="text-sm text-gray-600 dark:text-gray-300 mb-4">
                Status: <span class="font-bold">{{ ucwords(str_replace('_', ' ', $this->myOpenSession->status)) }}</span>
            </p>
            <button wire:click="goToOpenSession" class="px-4 py-3 rounded-lg bg-amber-500 hover:bg-amber-600 text-white font-bold kiosk-tap kiosk-primary-pulse">
                Continue Counting
            </button>
        </div>
    @else
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 max-w-xl">
            @if($this->hasActiveShift())
                <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-2">Start Your Count</h3>

                <div class="grid grid-cols-2 gap-3 mb-4">
                    <button type="button" wire:click="$set('isClosing', false)"
                        class="p-3 rounded-lg border-2 text-left font-bold text-sm {{ !$isClosing ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20 text-primary-700 dark:text-primary-300' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300' }}">
                        Hand over to someone
                        <div class="text-xs font-normal opacity-80 mt-0.5">They're taking over the shift</div>
                    </button>
                    <button type="button" wire:click="$set('isClosing', true)"
                        class="p-3 rounded-lg border-2 text-left font-bold text-sm {{ $isClosing ? 'border-amber-500 bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300' }}">
                        Close for the day
                        <div class="text-xs font-normal opacity-80 mt-0.5">Nobody's taking over right now</div>
                    </button>
                </div>

                <p class="text-sm text-gray-600 dark:text-gray-300 mb-4">
                    @if($isClosing)
                        Pick a second person to confirm your count. Your shift ends once it's reviewed — nobody else's shift starts from it.
                    @else
                        Pick who you're handing over to. They'll need to confirm your count on their own login before your shift can end.
                    @endif
                </p>

                <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">
                    {{ $isClosing ? 'Confirmed by' : 'Handing over to' }}
                </label>
                {{-- A button per person, not a dropdown list — a mis-tap on a
                     tiny native <select> option is exactly how a wrong (or
                     even the wrong-same) person has gotten picked before;
                     large tappable buttons with the selection clearly
                     highlighted are much harder to fat-finger. --}}
                <div class="grid grid-cols-2 gap-2 mb-4">
                    @forelse($this->candidateIncomingUsers as $user)
                        <button type="button" wire:click="$set('incomingUserId', {{ $user->id }})"
                            class="p-3 rounded-lg border-2 text-center font-bold text-sm touch-manipulation {{ (int) $incomingUserId === $user->id ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20 text-primary-700 dark:text-primary-300' : 'border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300' }}">
                            {{ $user->name }}
                        </button>
                    @empty
                        <p class="col-span-2 text-sm text-gray-500 dark:text-gray-400">No one else is set up for this role yet.</p>
                    @endforelse
                </div>

                <button wire:click="startCount" class="w-full px-4 py-3 rounded-lg bg-primary-600 hover:bg-primary-700 text-white font-bold kiosk-tap kiosk-primary-pulse">
                    {{ $isClosing ? 'Start Closing Count' : 'Start Handover Count' }}
                </button>
            @elseif($this->otherActiveCustodian)
                <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-2">Start an Unwitnessed Handover</h3>
                <p class="text-sm text-gray-600 dark:text-gray-300 mb-4">
                    {{ $this->otherActiveCustodian->name }} is still shown on shift but isn't here to count with you.
                    You'll count alone, and someone else needs to witness it — any staff member with a PIN. The
                    shortfall (if any) is still charged to {{ $this->otherActiveCustodian->name }}, not the witness.
                </p>

                <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Witness</label>
                <select wire:model="witnessUserId" class="w-full p-3 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-800 mb-4">
                    <option value="">Choose…</option>
                    @foreach($this->candidateWitnesses as $user)
                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                    @endforeach
                </select>

                <button wire:click="startCount" class="w-full px-4 py-3 rounded-lg bg-primary-600 hover:bg-primary-700 text-white font-bold kiosk-tap kiosk-primary-pulse">
                    Start Unwitnessed Count
                </button>
            @else
                <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-2">Start Your Opening Count</h3>
                <p class="text-sm text-gray-600 dark:text-gray-300 mb-4">
                    You're not currently on a shift — this is the first count of the day, so there's no one to hand over from. Once this count is reviewed, you can start your shift from it.
                </p>

                <button wire:click="startCount" class="w-full px-4 py-3 rounded-lg bg-primary-600 hover:bg-primary-700 text-white font-bold kiosk-tap kiosk-primary-pulse">
                    Start Opening Count
                </button>
            @endif
        </div>
    @endif
</x-filament-panels::page>
