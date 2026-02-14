<?php

namespace App\Filament\Pages;

use App\Models\Category;
use App\Models\Product;
use App\Services\PermissionService;
use BackedEnum;
use Filament\Pages\Page;

class PosPage extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-shopping-cart';
    protected string $view = 'filament.pages.pos-page';
    protected static ?string $title = 'POS';
    
    public $table_id;

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
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
