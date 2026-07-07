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
            <button wire:click="goToOpenSession" class="px-4 py-3 rounded-lg bg-amber-500 hover:bg-amber-600 text-white font-bold">
                Continue Counting
            </button>
        </div>
    @else
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 max-w-xl">
            @if($this->hasActiveShift())
                <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-2">Start Your Handover Count</h3>
                <p class="text-sm text-gray-600 dark:text-gray-300 mb-4">
                    You're on duty, so this is a handover — pick who you're handing over to. They'll need to confirm your count on their own login before your shift can end.
                </p>

                <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Handing over to</label>
                <select wire:model="incomingUserId" class="w-full p-3 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-800 mb-4">
                    <option value="">Choose…</option>
                    @foreach($this->candidateIncomingUsers as $user)
                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                    @endforeach
                </select>

                <button wire:click="startCount" class="w-full px-4 py-3 rounded-lg bg-primary-600 hover:bg-primary-700 text-white font-bold">
                    Start Handover Count
                </button>
            @else
                <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-2">Start Your Opening Count</h3>
                <p class="text-sm text-gray-600 dark:text-gray-300 mb-4">
                    You're not currently on a shift — this is the first count of the day, so there's no one to hand over from. Once this count is reviewed, you can start your shift from it.
                </p>

                <button wire:click="startCount" class="w-full px-4 py-3 rounded-lg bg-primary-600 hover:bg-primary-700 text-white font-bold">
                    Start Opening Count
                </button>
            @endif
        </div>
    @endif
</x-filament-panels::page>
