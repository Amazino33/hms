<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PagePermission extends Model
{
    protected $table = 'page_permissions';

    protected $fillable = [
        'page_class',
        'page_name',
        'role_name',
    ];

    public static function roleHasAccess(string $pageClass, string $roleName): bool
    {
        return static::where('page_class', $pageClass)
                    ->where('role_name', $roleName)
                    ->exists();
    }

    public static function getRolesForPage(string $pageClass): array
    {
        return static::where('page_class', $pageClass)
                    ->pluck('role_name')
                    ->toArray();
    }

    public static function getPagesForRole(string $roleName): array
    {
        return static::where('role_name', $roleName)
                    ->pluck('page_class')
                    ->toArray();
    }
}