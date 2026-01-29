<?php

namespace App\Livewire;

use Livewire\Component;

class PwaInstall extends Component
{
    public $showInstallButton = false;
    public $isInstalled = false;
    public $installing = false;
    public $installSuccess = false;
    public $installError = false;
    public $errorMessage = '';

    public function mount()
    {
        // Check if already installed
        $this->isInstalled = $this->checkIfInstalled();
    }

    public function checkIfInstalled()
    {
        // Check if running in standalone mode (installed PWA)
        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            // This will be properly checked via JavaScript
            return false;
        }
        return false;
    }

    public function installApp()
    {
        $this->installing = true;
        $this->installError = false;
        $this->errorMessage = '';

        // Dispatch event to trigger JavaScript install
        $this->dispatch('install-pwa');
    }

    public function markAsInstalled()
    {
        $this->isInstalled = true;
        $this->showInstallButton = false;
        $this->installing = false;
        $this->installSuccess = true;

        // Hide success message after 3 seconds
        $this->dispatch('hide-success-message');
    }

    public function markInstallError($message = 'Installation failed. Please try again.')
    {
        $this->installing = false;
        $this->installError = true;
        $this->errorMessage = $message;

        // Hide error message after 5 seconds
        $this->dispatch('hide-error-message');
    }

    public function dismissPrompt()
    {
        $this->showInstallButton = false;
    }

    public function render()
    {
        return view('livewire.pwa-install');
    }
}