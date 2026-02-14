<?php

namespace App\Filament\Pages;

use App\Models\PagePermission;
use App\Services\PermissionService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Spatie\Permission\Models\Role;
use BackedEnum;
use UnitEnum;

class PagePermissionsManager extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected string $view = 'filament.pages.page-permissions-manager';

    protected static string|UnitEnum|null $navigationGroup = 'System Management';

    protected static ?int $navigationSort = 100;

    public ?array $permissions = [];

    public function mount(): void
    {
        $this->loadCurrentPermissions();
    }

    protected function loadCurrentPermissions(): void
    {
        $pages = PermissionService::getAvailablePages();
        $roles = Role::all();

        $this->permissions = [];

        foreach ($pages as $pageClass => $pageName) {
            $this->permissions[$pageClass] = [
                'name' => $pageName,
                'roles' => PagePermission::getRolesForPage($pageClass),
            ];
        }
    }

    public function savePermissions(): void
    {
        // Clear existing permissions
        PagePermission::truncate();

        // Save new permissions
        foreach ($this->permissions ?? [] as $pageClass => $pageData) {
            foreach ($pageData['roles'] ?? [] as $roleName) {
                PagePermission::create([
                    'page_class' => $pageClass,
                    'page_name' => $pageData['name'] ?? $pageClass,
                    'role_name' => $roleName,
                ]);
            }
        }

        Notification::make()
            ->title('Permissions Updated')
            ->body('Page permissions have been saved successfully.')
            ->success()
            ->send();

        $this->loadCurrentPermissions();
    }

    public static function canAccess(): bool
    {
        return auth()->user()->hasRole(['super_admin', 'manager']);
    }
}