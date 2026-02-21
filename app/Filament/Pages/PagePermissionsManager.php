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

    /** Mirrors $permissions but keyed on widget class names. */
    public ?array $widgetPermissions = [];

    public function mount(): void
    {
        $this->loadCurrentPermissions();
    }

    protected function loadCurrentPermissions(): void
    {
        // ── Pages ────────────────────────────────────────────────────────────
        $pages = PermissionService::getAvailablePages();
        $this->permissions = [];

        foreach ($pages as $pageClass => $pageName) {
            $this->permissions[$pageClass] = [
                'name'  => $pageName,
                'roles' => PagePermission::getRolesForPage($pageClass),
            ];
        }

        // ── Widgets ──────────────────────────────────────────────────────────
        $widgets = PermissionService::getAvailableWidgets();
        $this->widgetPermissions = [];

        foreach ($widgets as $widgetClass => $widgetName) {
            $this->widgetPermissions[$widgetClass] = [
                'name'  => $widgetName,
                'roles' => PagePermission::getRolesForPage($widgetClass),
            ];
        }
    }

    public function savePermissions(): void
    {
        // Clear existing permissions for all pages AND widgets
        PagePermission::truncate();

        // Re-save page permissions
        foreach ($this->permissions ?? [] as $pageClass => $pageData) {
            foreach ($pageData['roles'] ?? [] as $roleName) {
                PagePermission::create([
                    'page_class' => $pageClass,
                    'page_name'  => $pageData['name'] ?? $pageClass,
                    'role_name'  => $roleName,
                ]);
            }
        }

        // Re-save widget permissions (stored in the same table, same columns)
        foreach ($this->widgetPermissions ?? [] as $widgetClass => $widgetData) {
            foreach ($widgetData['roles'] ?? [] as $roleName) {
                PagePermission::create([
                    'page_class' => $widgetClass,
                    'page_name'  => $widgetData['name'] ?? $widgetClass,
                    'role_name'  => $roleName,
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