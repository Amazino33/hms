<?php

namespace App\Livewire;

use Livewire\Component;

class PwaInstall extends Component
{
    public $showInstallButton = false;
    public $isInstalled = false;

    public function mount()
    {
        // Check if already installed
        $this->isInstalled = $this->checkIfInstalled();
    }

    public function checkIfInstalled()
    {
        // This will be checked via JavaScript
        return false;
    }

    public function installApp()
    {
        // This will trigger the JavaScript install function
        $this->dispatch('install-pwa');
    }

    public function render()
    {
        return view('livewire.pwa-install');
    }
}