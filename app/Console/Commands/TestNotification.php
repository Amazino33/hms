<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Filament\Notifications\Notification;
use App\Models\User;

class TestNotification extends Command
{
    protected $signature = 'test:notification';
    protected $description = 'Send a test notification to check the badge';

    public function handle()
    {
        $user = User::first();
        if (!$user) {
            $this->error('No users found');
            return;
        }

        Notification::make()
            ->title('Test Notification')
            ->body('This is a test to check if the notification badge appears')
            ->info()
            ->actions([
                \Filament\Actions\Action::make('view')
                    ->button()
                    ->url('/admin'),
            ])
            ->sendToDatabase($user);

        $this->info("Test notification sent to {$user->name}");
    }
}