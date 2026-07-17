<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use BezhanSalleh\FilamentShield\Support\Utils;
use Spatie\Permission\PermissionRegistrar;

class ShieldSeeder extends Seeder
{
    /**
     * The single source of truth for which roles hold which Shield
     * permissions — used both by run() (full sync, safe for a fresh/local
     * database) and by deploy.sh (additive-only via givePermissionTo, safe
     * to re-run against a live database without erasing anything configured
     * directly through the Shield UI in production).
     *
     * @return array<int, array{name: string, guard_name: string, permissions: array<int, string>}>
     */
    public static function getRolesWithPermissions(): array
    {
        return json_decode(static::rolesWithPermissionsJson(), true);
    }

    private static function rolesWithPermissionsJson(): string
    {
        return '[{"name":"super_admin","guard_name":"web","permissions":["access admin","ViewAny:User","View:User","Create:User","Update:User","Delete:User","ViewAny:Role","View:Role","Create:Role","Update:Role","Delete:Role","ViewAny:Permission","View:Permission","Create:Permission","Update:Permission","Delete:Permission","ViewAny:Booking","View:Booking","Create:Booking","Update:Booking","Delete:Booking","Restore:Booking","ForceDelete:Booking","ForceDeleteAny:Booking","RestoreAny:Booking","Replicate:Booking","Reorder:Booking","ViewAny:Category","View:Category","Create:Category","Update:Category","Delete:Category","Restore:Category","ForceDelete:Category","ForceDeleteAny:Category","RestoreAny:Category","Replicate:Category","Reorder:Category","ViewAny:Guest","View:Guest","Create:Guest","Update:Guest","Delete:Guest","Restore:Guest","ForceDelete:Guest","ForceDeleteAny:Guest","RestoreAny:Guest","Replicate:Guest","Reorder:Guest","ViewAny:Ingredient","View:Ingredient","Create:Ingredient","Update:Ingredient","Delete:Ingredient","Restore:Ingredient","ForceDelete:Ingredient","ForceDeleteAny:Ingredient","RestoreAny:Ingredient","Replicate:Ingredient","Reorder:Ingredient","ViewAny:MenuItem","View:MenuItem","Create:MenuItem","Update:MenuItem","Delete:MenuItem","Restore:MenuItem","ForceDelete:MenuItem","ForceDeleteAny:MenuItem","RestoreAny:MenuItem","Replicate:MenuItem","Reorder:MenuItem","ViewAny:Order","View:Order","Create:Order","Update:Order","Delete:Order","Restore:Order","ForceDelete:Order","ForceDeleteAny:Order","RestoreAny:Order","Replicate:Order","Reorder:Order","ViewAny:Product","View:Product","Create:Product","Update:Product","Delete:Product","Restore:Product","ForceDelete:Product","ForceDeleteAny:Product","RestoreAny:Product","Replicate:Product","Reorder:Product","ViewAny:Room","View:Room","Create:Room","Update:Room","Delete:Room","Restore:Room","ForceDelete:Room","ForceDeleteAny:Room","RestoreAny:Room","Replicate:Room","Reorder:Room","ViewAny:Shift","View:Shift","Create:Shift","Update:Shift","Delete:Shift","Restore:Shift","ForceDelete:Shift","ForceDeleteAny:Shift","RestoreAny:Shift","Replicate:Shift","Reorder:Shift","ViewAny:Supplier","View:Supplier","Create:Supplier","Update:Supplier","Delete:Supplier","Restore:Supplier","ForceDelete:Supplier","ForceDeleteAny:Supplier","RestoreAny:Supplier","Replicate:Supplier","Reorder:Supplier","ViewAny:Table","View:Table","Create:Table","Update:Table","Delete:Table","Restore:Table","ForceDelete:Table","ForceDeleteAny:Table","RestoreAny:Table","Replicate:Table","Reorder:Table","Restore:User","ForceDelete:User","ForceDeleteAny:User","RestoreAny:User","Replicate:User","Reorder:User","ViewAny:WareHouse","View:WareHouse","Create:WareHouse","Update:WareHouse","Delete:WareHouse","Restore:WareHouse","ForceDelete:WareHouse","ForceDeleteAny:WareHouse","RestoreAny:WareHouse","Replicate:WareHouse","Reorder:WareHouse","Restore:Role","ForceDelete:Role","ForceDeleteAny:Role","RestoreAny:Role","Replicate:Role","Reorder:Role","View:BarDisplay","View:DailyReport","View:FloorPlan","View:KitchenDisplay","View:ManageCompanySettings","View:MyHistory","View:MyShiftReport","View:PagePermissionsManager","View:PosPage","View:QuickInventoryUpdate","View:ReceiveTransfers","View:StaffShiftsReport","View:StockValuation","View:StorekeeperTransfers","View:TableDetail","View:LowStockAlertsWidget","View:PwaInstallWidget","View:StaffCashSummary","View:StatOverview","View:StockValuationOverview","View:WaiterCommissionWidget","View:WaiterShiftStats","View:SalesChart","ViewAny:StaffDebt","View:StaffDebt","Create:StaffDebt","Update:StaffDebt","Delete:StaffDebt","Restore:StaffDebt","ForceDelete:StaffDebt","ForceDeleteAny:StaffDebt","RestoreAny:StaffDebt","Replicate:StaffDebt","Reorder:StaffDebt","ViewAny:StockAdjustment","View:StockAdjustment","Create:StockAdjustment","Update:StockAdjustment","Delete:StockAdjustment","Restore:StockAdjustment","ForceDelete:StockAdjustment","ForceDeleteAny:StockAdjustment","RestoreAny:StockAdjustment","Replicate:StockAdjustment","Reorder:StockAdjustment","ViewAny:KioskDevice","View:KioskDevice","Create:KioskDevice","Update:KioskDevice","Delete:KioskDevice","Restore:KioskDevice","ForceDelete:KioskDevice","ForceDeleteAny:KioskDevice","RestoreAny:KioskDevice","Replicate:KioskDevice","Reorder:KioskDevice","update-price-via-procurement","ViewAny:Expense","View:Expense","Create:Expense","Update:Expense","Delete:Expense","ViewAny:ExpenseCategory","View:ExpenseCategory","Create:ExpenseCategory","Update:ExpenseCategory"]},{"name":"admin","guard_name":"web","permissions":["access admin","ViewAny:User","View:User","Create:User","Update:User","Delete:User","ViewAny:StaffDebt","View:StaffDebt","Create:StaffDebt","Update:StaffDebt","Delete:StaffDebt","Restore:StaffDebt","ForceDelete:StaffDebt","ForceDeleteAny:StaffDebt","RestoreAny:StaffDebt","Replicate:StaffDebt","Reorder:StaffDebt","ViewAny:StockAdjustment","View:StockAdjustment","Create:StockAdjustment","Update:StockAdjustment","Delete:StockAdjustment","Restore:StockAdjustment","ForceDelete:StockAdjustment","ForceDeleteAny:StockAdjustment","RestoreAny:StockAdjustment","Replicate:StockAdjustment","Reorder:StockAdjustment","ViewAny:KioskDevice","View:KioskDevice","Create:KioskDevice","Update:KioskDevice","Delete:KioskDevice","Restore:KioskDevice","ForceDelete:KioskDevice","ForceDeleteAny:KioskDevice","RestoreAny:KioskDevice","Replicate:KioskDevice","Reorder:KioskDevice"]},{"name":"chef","guard_name":"web","permissions":["ViewAny:StockAdjustment","View:StockAdjustment","Create:StockAdjustment"]},{"name":"manager","guard_name":"web","permissions":["ViewAny:StaffDebt","View:StaffDebt","Create:StaffDebt","Update:StaffDebt","Delete:StaffDebt","Restore:StaffDebt","ForceDelete:StaffDebt","ForceDeleteAny:StaffDebt","RestoreAny:StaffDebt","Replicate:StaffDebt","Reorder:StaffDebt","ViewAny:StockAdjustment","View:StockAdjustment","Create:StockAdjustment","Update:StockAdjustment","Delete:StockAdjustment","Restore:StockAdjustment","ForceDelete:StockAdjustment","ForceDeleteAny:StockAdjustment","RestoreAny:StockAdjustment","Replicate:StockAdjustment","Reorder:StockAdjustment","update-price-via-procurement","ViewAny:Expense","View:Expense","Create:Expense","Update:Expense"]},{"name":"waiter","guard_name":"web","permissions":[]},{"name":"bartender","guard_name":"web","permissions":["ViewAny:StockAdjustment","View:StockAdjustment","Create:StockAdjustment"]},{"name":"storekeeper","guard_name":"web","permissions":["ViewAny:StockAdjustment","View:StockAdjustment","Create:StockAdjustment","update-price-via-procurement"]},{"name":"receptionist","guard_name":"web","permissions":[]},{"name":"porter","guard_name":"web","permissions":[]},{"name":"cashier","guard_name":"web","permissions":[]},{"name":"ceo","guard_name":"web","permissions":[]}]';
    }

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $tenants = '[]';
        $users = '[]';
        $userTenantPivot = '[]';
        $rolesWithPermissions = static::rolesWithPermissionsJson();
        $directPermissions = '[]';

        // 1. Seed tenants first (if present)
        if (! blank($tenants) && $tenants !== '[]') {
            static::seedTenants($tenants);
        }

        // 2. Seed roles with permissions
        static::makeRolesWithPermissions($rolesWithPermissions);

        // 3. Seed direct permissions
        static::makeDirectPermissions($directPermissions);

        // 4. Seed users with their roles/permissions (if present)
        if (! blank($users) && $users !== '[]') {
            static::seedUsers($users);
        }

        // 5. Seed user-tenant pivot (if present)
        if (! blank($userTenantPivot) && $userTenantPivot !== '[]') {
            static::seedUserTenantPivot($userTenantPivot);
        }

        $this->command->info('Shield Seeding Completed.');
    }

    protected static function seedTenants(string $tenants): void
    {
        if (blank($tenantData = json_decode($tenants, true))) {
            return;
        }

        $tenantModel = '';
        if (blank($tenantModel)) {
            return;
        }

        foreach ($tenantData as $tenant) {
            $tenantModel::firstOrCreate(
                ['id' => $tenant['id']],
                $tenant
            );
        }
    }

    protected static function seedUsers(string $users): void
    {
        if (blank($userData = json_decode($users, true))) {
            return;
        }

        $userModel = 'App\Models\User';
        $tenancyEnabled = false;

        foreach ($userData as $data) {
            // Extract role/permission data before creating user
            $roles = $data['roles'] ?? [];
            $permissions = $data['permissions'] ?? [];
            $tenantRoles = $data['tenant_roles'] ?? [];
            $tenantPermissions = $data['tenant_permissions'] ?? [];
            unset($data['roles'], $data['permissions'], $data['tenant_roles'], $data['tenant_permissions']);

            $user = $userModel::firstOrCreate(
                ['email' => $data['email']],
                $data
            );

            // Handle tenancy mode - sync roles/permissions per tenant
            if ($tenancyEnabled && (! empty($tenantRoles) || ! empty($tenantPermissions))) {
                foreach ($tenantRoles as $tenantId => $roleNames) {
                    $contextId = $tenantId === '_global' ? null : $tenantId;
                    setPermissionsTeamId($contextId);
                    $user->syncRoles($roleNames);
                }

                foreach ($tenantPermissions as $tenantId => $permissionNames) {
                    $contextId = $tenantId === '_global' ? null : $tenantId;
                    setPermissionsTeamId($contextId);
                    $user->syncPermissions($permissionNames);
                }
            } else {
                // Non-tenancy mode
                if (! empty($roles)) {
                    $user->syncRoles($roles);
                }

                if (! empty($permissions)) {
                    $user->syncPermissions($permissions);
                }
            }
        }
    }

    protected static function seedUserTenantPivot(string $pivot): void
    {
        if (blank($pivotData = json_decode($pivot, true))) {
            return;
        }

        $pivotTable = '';
        if (blank($pivotTable)) {
            return;
        }

        foreach ($pivotData as $row) {
            $uniqueKeys = [];

            if (isset($row['user_id'])) {
                $uniqueKeys['user_id'] = $row['user_id'];
            }

            $tenantForeignKey = 'team_id';
            if (! blank($tenantForeignKey) && isset($row[$tenantForeignKey])) {
                $uniqueKeys[$tenantForeignKey] = $row[$tenantForeignKey];
            }

            if (! empty($uniqueKeys)) {
                DB::table($pivotTable)->updateOrInsert($uniqueKeys, $row);
            }
        }
    }

    protected static function makeRolesWithPermissions(string $rolesWithPermissions): void
    {
        if (blank($rolePlusPermissions = json_decode($rolesWithPermissions, true))) {
            return;
        }

        /** @var \Illuminate\Database\Eloquent\Model $roleModel */
        $roleModel = Utils::getRoleModel();
        /** @var \Illuminate\Database\Eloquent\Model $permissionModel */
        $permissionModel = Utils::getPermissionModel();

        $tenancyEnabled = false;
        $teamForeignKey = 'team_id';

        foreach ($rolePlusPermissions as $rolePlusPermission) {
            $tenantId = $rolePlusPermission[$teamForeignKey] ?? null;

            // Set tenant context for role creation and permission sync
            if ($tenancyEnabled) {
                setPermissionsTeamId($tenantId);
            }

            $roleData = [
                'name' => $rolePlusPermission['name'],
                'guard_name' => $rolePlusPermission['guard_name'],
            ];

            // Include tenant ID in role data (can be null for global roles)
            if ($tenancyEnabled && ! blank($teamForeignKey)) {
                $roleData[$teamForeignKey] = $tenantId;
            }

            $role = $roleModel::firstOrCreate($roleData);

            if (! blank($rolePlusPermission['permissions'])) {
                $permissionModels = collect($rolePlusPermission['permissions'])
                    ->map(fn ($permission) => $permissionModel::firstOrCreate([
                        'name' => $permission,
                        'guard_name' => $rolePlusPermission['guard_name'],
                    ]))
                    ->all();

                $role->syncPermissions($permissionModels);
            }
        }
    }

    public static function makeDirectPermissions(string $directPermissions): void
    {
        if (blank($permissions = json_decode($directPermissions, true))) {
            return;
        }

        /** @var \Illuminate\Database\Eloquent\Model $permissionModel */
        $permissionModel = Utils::getPermissionModel();

        foreach ($permissions as $permission) {
            if ($permissionModel::whereName($permission['name'])->doesntExist()) {
                $permissionModel::create([
                    'name' => $permission['name'],
                    'guard_name' => $permission['guard_name'],
                ]);
            }
        }
    }
}
