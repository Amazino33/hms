<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use BackedEnum;

class PosPage extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-shopping-cart';
    protected string $view = 'filament.pages.pos-page';

    public static function canAccess(): bool
{
    // Only Super Admins and Waiters can see this
    return auth()->user()->hasRole(['super_admin', 'waiter']);
}
}
