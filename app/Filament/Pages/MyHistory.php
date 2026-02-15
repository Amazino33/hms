<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use BackedEnum;
use Illuminate\Support\Facades\Auth;
use App\Services\PermissionService;
use App\Services\StaffReportService;

class MyHistory extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-clock';
    protected static ?string $navigationLabel = 'My History';
    protected string $view = 'filament.pages.my-history';

    public function getViewData(): array
    {
        $user = Auth::user();
        $service = new StaffReportService();

        // Last 30 days by default
        $history = $service->staffDailyHistory($user->id);

        return [
            'history' => $history,
            'user' => $user,
        ];
    }

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }
}
