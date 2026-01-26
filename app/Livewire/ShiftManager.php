<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Shift;
use Filament\Notifications\Notification;

class ShiftManager extends Component
{
    public $currentShift;
    public $shiftDuration;

    public function mount()
    {
        $this->loadCurrentShift();
    }

    public function loadCurrentShift()
    {
        $this->currentShift = auth()->user()->currentShift();
        if ($this->currentShift) {
            $seconds = \Carbon\Carbon::now()->diffInSeconds($this->currentShift->started_at);
            $this->shiftDuration = round($seconds / 60, 1);
        }
    }

    public function startShift()
    {
        try {
            $shift = auth()->user()->startShift();
            $this->loadCurrentShift();
            Notification::make()->title('Shift Started')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Error starting shift')->danger()->send();
        }
    }

    public function endShift()
    {
        try {
            $shift = auth()->user()->endShift();
            $this->loadCurrentShift();
            Notification::make()->title('Shift Ended Successfully')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Error ending shift')->danger()->send();
        }
    }

    public function render()
    {
        return view('livewire.shift-manager');
    }
}