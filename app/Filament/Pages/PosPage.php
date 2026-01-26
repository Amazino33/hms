<?php

namespace App\Filament\Pages;

use App\Models\Category;
use App\Models\Product;
use Filament\Pages\Page;
use BackedEnum;

class PosPage extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-shopping-cart';
    protected string $view = 'filament.pages.pos-page';
    
    public $table_id;

    public static function canAccess(): bool
    {
        // Only Super Admins and Waiters can see this
        return auth()->user()->hasRole(['super_admin', 'waiter']);
    }

    public function mount()
    {
        $this->table_id = request('table_id');
    }

    public function getTitle(): string
    {
        return '';
    }
}
