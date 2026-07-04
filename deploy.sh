#!/bin/bash
# Production deploy script — safe to re-run on every deploy.
#
# What this does, in order:
#   1. Backs up the database (never skip this)
#   2. Puts the site in maintenance mode
#   3. Pulls the latest code from GitHub
#   4. Installs PHP dependencies (no npm needed — compiled assets are
#      committed to git, since this server can't run npm)
#   5. Runs database migrations
#   6. Ensures roles/permissions exist — additive only, never removes or
#      overwrites anything already configured live via the Shield UI
#   7. Rebuilds caches and restarts the queue
#   8. Brings the site back out of maintenance mode
#
# Usage: ./deploy.sh   (run from the project root)

set -e  # stop immediately if any step fails

echo "=================================================="
echo " HMS Deploy — $(date)"
echo "=================================================="

echo ""
echo "==> [1/8] Backing up database..."
mkdir -p storage/backups
DB_CONFIG=$(php artisan tinker --execute="echo config('database.connections.mysql.database').'|'.config('database.connections.mysql.username').'|'.config('database.connections.mysql.password').'|'.config('database.connections.mysql.host');" 2>/dev/null | tail -1)
IFS='|' read -r DB_DATABASE DB_USERNAME DB_PASSWORD DB_HOST <<< "$DB_CONFIG"
BACKUP_FILE="storage/backups/pre_deploy_$(date +%Y%m%d_%H%M%S).sql"
mysqldump -h "$DB_HOST" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" > "$BACKUP_FILE"
echo "    Backup saved to $BACKUP_FILE"

echo ""
echo "==> [2/8] Enabling maintenance mode..."
php artisan down --retry=60 || true

echo ""
echo "==> [3/8] Pulling latest code from GitHub..."
# --ff-only: fail loudly instead of creating a surprise merge commit if
# history has diverged (e.g. someone edited something directly on the server)
git pull --ff-only origin main

echo ""
echo "==> [4/8] Installing PHP dependencies..."
composer install --no-dev --optimize-autoloader

echo ""
echo "==> [5/8] Running database migrations..."
php artisan migrate --force

echo ""
echo "==> [6/8] Ensuring roles and new permissions exist (additive only)..."
php artisan tinker --execute="
foreach (['super_admin','admin','manager','waiter','chef','bartender','storekeeper','porter'] as \$name) {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => \$name, 'guard_name' => 'web']);
}

foreach (['ViewAny','View','Create','Update','Delete','Restore','ForceDelete','ForceDeleteAny','RestoreAny','Replicate','Reorder'] as \$action) {
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => \$action.':StaffDebt', 'guard_name' => 'web']);
}

\$perms = \Spatie\Permission\Models\Permission::where('name', 'like', '%:StaffDebt')->pluck('name')->all();
foreach (['super_admin', 'admin', 'manager'] as \$roleName) {
    \$role = \Spatie\Permission\Models\Role::where('name', \$roleName)->first();
    if (\$role) { \$role->givePermissionTo(\$perms); }
}

echo 'Roles and StaffDebt permissions ensured.' . PHP_EOL;
"
php artisan db:seed --class=PagePermissionsSeeder --force

echo ""
echo "==> [7/8] Rebuilding caches and restarting queue..."
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart

echo ""
echo "==> [8/8] Disabling maintenance mode..."
php artisan up

echo ""
echo "=================================================="
echo " Deploy complete — $(date)"
echo " Backup: $BACKUP_FILE"
echo "=================================================="
