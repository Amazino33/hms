# Deployment Guide

## ✅ What Works Automatically (Yes, it will work!)

When you commit to GitHub and pull on the client side, **most optimizations will work automatically**. Here's what's included:

### Files That Will Be Committed:
- ✅ All PHP files (controllers, models, middleware, commands)
- ✅ Configuration changes ([vite.config.js](vite.config.js), [bootstrap/app.php](bootstrap/app.php), [bootstrap/providers.php](bootstrap/providers.php))
- ✅ PWA files ([public/sw.js](public/sw.js), [public/site.webmanifest](public/site.webmanifest))
- ✅ Routes and views
- ✅ Migration files
- ✅ Documentation ([PERFORMANCE_GUIDE.md](PERFORMANCE_GUIDE.md), [PWA_TROUBLESHOOTING.md](PWA_TROUBLESHOOTING.md))

### Files NOT Committed (in .gitignore):
- ❌ `.env` - Environment configuration
- ❌ `/vendor` - Composer dependencies
- ❌ `/node_modules` - NPM dependencies
- ❌ `/public/build` - Compiled assets

## 🚀 Setup on Client Side (After Pull)

### Step 1: Install Dependencies

```bash
# Install PHP dependencies
composer install

# Install JavaScript dependencies
npm install
```

### Step 2: Configure Environment

Copy the example environment file and configure it:

```bash
# Copy .env.example to .env
cp .env.example .env

# Generate application key
php artisan key:generate
```

Then edit `.env` and update these important settings:

```env
# Application
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password

# Session & Cache (for performance)
SESSION_DRIVER=database
SESSION_LIFETIME=120

CACHE_STORE=file
CACHE_PREFIX=hms_cache

# PWA
PWA_MANIFEST_NAME="HMS App"
PWA_MANIFEST_SHORT_NAME="HMS"
```

### Step 3: Setup Database

```bash
# Run migrations (creates sessions table needed for performance)
php artisan migrate

# Optional: Seed database with sample data
php artisan db:seed
```

### Step 4: Build Assets

```bash
# Build optimized production assets
npm run build
```

This creates minified, optimized CSS/JS files in `/public/build` directory.

### Step 5: Optimize Application

```bash
# Run all optimizations in one command
php artisan app:optimize --full
```

This will:
- Cache configuration
- Cache routes
- Cache views
- Optimize autoloader
- Cache Filament components

### Step 6: Enable HTTPS (Required for PWA)

#### For Production Server:

Configure SSL certificate with your hosting provider or use:

```bash
# Using Certbot (Let's Encrypt)
sudo certbot --nginx -d your-domain.com
```

#### For Local Development (Laravel Herd):

```bash
herd secure your-site-name
```

#### For Local Development (Valet):

```bash
valet secure your-site-name
```

### Step 7: Set Permissions (Linux/Mac)

```bash
# Storage and cache directories need write permissions
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

## ✅ Verify Everything Works

### 1. Check PWA Functionality

Visit `https://your-domain.com/pwa-test` to verify:
- Service Worker registration ✓
- Manifest detected ✓
- HTTPS enabled ✓
- Install prompt available ✓

### 2. Test Performance

Open browser DevTools (F12) → Network tab:
- First load should be < 2 seconds
- Assets should show "from ServiceWorker" on repeat visits
- Check Response Headers for cache headers

### 3. Verify Optimizations

```bash
# Check if caches are working
php artisan route:list  # Should be very fast
php artisan config:show app  # Should show cached config

# Check for errors
php artisan optimize:clear  # If something's wrong
php artisan app:optimize --full  # Rebuild caches
```

## 📦 Production Deployment Checklist

Before going live, ensure:

- [ ] `.env` configured with production settings
- [ ] `APP_DEBUG=false` in `.env`
- [ ] `APP_ENV=production` in `.env`
- [ ] Database migrated: `php artisan migrate --force`
- [ ] Assets built: `npm run build`
- [ ] Application optimized: `php artisan app:optimize --full`
- [ ] HTTPS enabled and working
- [ ] File permissions set correctly
- [ ] Composer dependencies installed: `composer install --no-dev --optimize-autoloader`
- [ ] Storage linked: `php artisan storage:link`
- [ ] PHP OPcache enabled in php.ini
- [ ] Service worker cache version updated (if needed)

## 🔄 Updating After New Commits

When you pull new changes from GitHub:

```bash
# 1. Pull changes
git pull origin main

# 2. Update dependencies
composer install --no-dev --optimize-autoloader
npm install

# 3. Rebuild assets
npm run build

# 4. Run migrations (if any new ones)
php artisan migrate --force

# 5. Clear and rebuild caches
php artisan app:optimize --clear
php artisan app:optimize --full

# 6. Restart queue workers (if using queues)
php artisan queue:restart
```

## 🐛 Troubleshooting

### "Service Worker not found"
- Make sure you ran `npm run build`
- Check that HTTPS is enabled
- Clear browser cache and hard refresh (Ctrl+Shift+R)

### "Changes not reflecting"
```bash
php artisan app:optimize --clear
npm run build
php artisan app:optimize --full
```

### "White screen or 500 error"
```bash
# Check logs
tail -f storage/logs/laravel.log

# Fix permissions
chmod -R 775 storage bootstrap/cache

# Rebuild everything
composer dump-autoload
php artisan optimize:clear
php artisan app:optimize --full
```

### "Install button not showing"
- Verify HTTPS is working (PWA requires HTTPS)
- Check [public/site.webmanifest](public/site.webmanifest) is accessible
- Visit `/pwa-test` to run diagnostics
- Clear Service Worker in DevTools → Application → Service Workers

## 🎯 Performance Best Practices

### On Every Deployment:
1. Always run `npm run build` (not `npm run dev`)
2. Always run `php artisan app:optimize --full`
3. Clear old caches before rebuilding

### For Maximum Performance:
- Enable Redis for caching (faster than file cache)
- Use Laravel Octane for 10x better performance
- Enable PHP OPcache in production
- Use CDN for static assets

### Monitoring:
```bash
# Check what's cached
php artisan config:show cache
php artisan route:list

# Monitor performance
php artisan queue:monitor
php artisan horizon  # If using Laravel Horizon
```

## 📝 Important Notes

### `.env` File
The `.env` file is NOT committed to Git for security reasons. You need to:
1. Copy `.env.example` to `.env` on the client
2. Update database credentials
3. Set `SESSION_DRIVER=database` and `CACHE_STORE=file` for performance

### Built Assets
The `/public/build` directory is NOT committed. You must run `npm run build` on the client side to generate optimized assets.

### Vendor Directory
The `/vendor` directory is NOT committed. You must run `composer install` on the client side to install PHP dependencies.

### Sessions Table
The performance optimizations use database sessions. Make sure to run:
```bash
php artisan migrate
```

This creates the `sessions` table needed for the `SESSION_DRIVER=database` setting.

---

## 🎉 Summary

**Yes, your optimizations will work after commit and pull!**

Just remember to:
1. Run `composer install` and `npm install`
2. Configure `.env` file
3. Run `php artisan migrate`
4. Run `npm run build`
5. Run `php artisan app:optimize --full`
6. Enable HTTPS

Everything else (PHP code, middleware, commands, PWA files) will work automatically once you complete these steps.
