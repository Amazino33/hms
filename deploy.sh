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
# Uses the company's configured message/duration/secret (Company Settings
# in the admin panel) so the maintenance page and bypass URL are the same
# whether triggered from here or from that page directly.
php artisan hms:maintenance-down || true

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
# Previously hardcoded to StaffDebt permissions only — every new Shield
# resource since then (StockAdjustment, KioskDevice, ...) silently never
# reached production because this step never granted them. Now reads the
# SAME role/permission map ShieldSeeder.php uses (single source of truth,
# updated automatically whenever `php artisan shield:generate` regenerates
# it), applied via givePermissionTo (never syncPermissions) so nothing
# configured live via the Shield UI — including roles not in this list at
# all, like custom ones added directly in production — is ever touched.
php artisan tinker --execute="
foreach (\Database\Seeders\ShieldSeeder::getRolesWithPermissions() as \$entry) {
    \$role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => \$entry['name'], 'guard_name' => \$entry['guard_name']]);

    if (empty(\$entry['permissions'])) {
        continue;
    }

    \$permissionModels = collect(\$entry['permissions'])
        ->map(fn (\$name) => \Spatie\Permission\Models\Permission::firstOrCreate(['name' => \$name, 'guard_name' => \$entry['guard_name']]))
        ->all();

    \$role->givePermissionTo(\$permissionModels);
}

echo 'Roles and Shield permissions ensured additively.' . PHP_EOL;
"
php artisan db:seed --class=PagePermissionsSeeder --force

echo ""
echo "==> [7/8] Rebuilding caches and restarting queue..."
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
# The app-level cache (e.g. each user's 1-hour-cached sidebar HTML) is a
# separate store from the three above and none of them touch it — without
# this, a Blade fix to a cached partial silently doesn't reach anyone with
# an existing cache entry until it happens to expire on its own.
php artisan cache:clear
php artisan queue:restart

echo ""
echo "==> [8/8] Disabling maintenance mode..."
php artisan hms:maintenance-up

echo ""
echo "==> Clearing PHP-FPM's OPcache..."
# This SSH session's `php` CLI has its own OPcache instance, separate from
# the one PHP-FPM uses to actually serve web requests — config:cache/
# route:cache/view:cache above only refresh what's on disk, they never
# touch PHP-FPM's already-compiled bytecode. Without this, a deploy can
# update every file and still silently keep serving old behavior on the
# live site until PHP-FPM happens to restart on its own. Hitting this
# route runs opcache_reset() from inside an actual web-server request.
APP_URL_VALUE=$(php artisan tinker --execute="echo config('app.url');" 2>/dev/null | tail -1)
OPCACHE_TOKEN=$(php artisan tinker --execute="echo hash('sha256', config('app.key'));" 2>/dev/null | tail -1)
curl -s "${APP_URL_VALUE}/__ops/reset-opcache/${OPCACHE_TOKEN}" || echo "    (could not reach the reset endpoint — check manually)"

echo ""
echo "=================================================="
echo " Deploy complete — $(date)"
echo " Backup: $BACKUP_FILE"
echo "=================================================="
