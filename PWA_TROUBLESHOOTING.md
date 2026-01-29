# HMS PWA Installation Guide

## ✅ PWA Setup Complete!

Your HMS app is now configured as a Progressive Web App (PWA) and ready to be installed on mobile phones and PCs.

## 🔧 How to Test Installation

### Step 1: Access the Test Page
Visit: **http://your-domain.test/pwa-test** (or use your local domain)

This page will show you:
- ✅ PWA requirements status
- 📱 Manifest information
- ⚙️ Service Worker status
- 🚀 Install button (when available)

### Step 2: Install on Desktop (Chrome/Edge)

1. Open your HMS app in Chrome or Edge
2. Look for the **install icon** (⊕ or 🖥️) in the address bar
3. Click it and select "Install"
4. Or use the menu: **Three dots → Install HMS**

**Alternative:** Visit `/pwa-test` and click the "Install HMS App" button

### Step 3: Install on Android

1. Open your HMS app in Chrome
2. Tap the **three-dot menu** (⋮)
3. Select **"Add to Home screen"** or **"Install app"**
4. Confirm the installation
5. The app icon will appear on your home screen

### Step 4: Install on iOS (iPhone/iPad)

**Note:** iOS has limited PWA support and requires manual steps:

1. Open your HMS app in **Safari** (must use Safari, not Chrome)
2. Tap the **Share button** (□↑)
3. Scroll and tap **"Add to Home Screen"**
4. Edit the name if desired
5. Tap **"Add"**

⚠️ **iOS Limitations:**
- Must use Safari browser
- No automatic install prompt
- Limited background capabilities
- Service Worker support is basic

## 🔍 Troubleshooting

### Issue: "Install button doesn't appear"

**Possible causes:**

1. **Not using HTTPS in production**
   - Solution: Deploy with HTTPS or use localhost for testing
   - Check: Visit `/pwa-test` to verify HTTPS status

2. **Service Worker not registered**
   - Solution: Open browser DevTools → Application → Service Workers
   - Should see: `/sw.js` registered
   - Fix: Clear cache and reload

3. **Already installed**
   - Check: Visit `/pwa-test`
   - If showing "Already installed", the app is already installed
   - To reinstall: Uninstall first, then clear browser data

4. **Browser doesn't support PWA**
   - Chrome/Edge: ✅ Full support
   - Firefox: ⚠️ Limited support (desktop only)
   - Safari: ⚠️ Limited support (iOS manual install only)
   - Opera: ✅ Full support

### Issue: "Service Worker registration failed"

**Solutions:**

1. **Check browser console** (F12 → Console)
   - Look for error messages
   - Common errors:
     - MIME type error: Check server configuration
     - HTTPS required: Use HTTPS or localhost

2. **Clear browser cache**
   - Chrome: DevTools → Application → Clear storage → Clear site data
   - Or: Settings → Privacy → Clear browsing data

3. **Verify file exists**
   - Visit: `http://your-domain.test/sw.js`
   - Should see JavaScript code (not 404 error)

4. **Check headers**
   - Service-Worker-Allowed header should be present
   - Content-Type should be `application/javascript`

### Issue: "Manifest not loading"

**Solutions:**

1. **Verify manifest exists**
   - Visit: `http://your-domain.test/site.webmanifest`
   - Should see JSON data

2. **Check manifest link in HTML**
   - View page source
   - Should see: `<link rel="manifest" href="/site.webmanifest">`

3. **Validate manifest**
   - Chrome: DevTools → Application → Manifest
   - Should show app name, icons, etc.
   - Fix any errors shown

4. **Check icons**
   - Icons must exist: `/apple-touch-icon.png`, `/favicon.ico`
   - Use 192x192 and 512x512 sizes for best results

### Issue: "App doesn't work offline"

**Solutions:**

1. **Service Worker must be active**
   - Visit: DevTools → Application → Service Workers
   - Status should be "activated and is running"

2. **Cache needs time to populate**
   - After installation, visit main pages
   - Service Worker caches pages as you browse
   - After caching, go offline and test

3. **Test offline mode**
   - DevTools → Network → Throttling → Offline
   - Reload page - should show cached content or offline page

### Issue: "iOS Safari won't install"

**iOS-specific solutions:**

1. **Must use Safari browser** (not Chrome)
2. **Domain must be HTTPS** (even for testing)
3. **Manifest must be valid** - no errors
4. **Icons must be properly sized** (180x180 for iOS)
5. **Follow manual steps** (no automatic prompt on iOS)

## 📱 Testing Checklist

Use `/pwa-test` to verify:

- [ ] HTTPS or localhost ✓
- [ ] Service Worker supported ✓
- [ ] Manifest link found ✓
- [ ] Service Worker registered ✓
- [ ] Install prompt appears ✓
- [ ] Icons load correctly ✓
- [ ] App installs successfully ✓
- [ ] App works offline ✓

## 🚀 Production Deployment

Before deploying to production:

1. **Enable HTTPS** (required for PWA)
   - Use Let's Encrypt, Cloudflare, or your hosting provider
   - PWA will not work on HTTP in production

2. **Update manifest URLs**
   - Check `start_url` in manifest
   - Update any hardcoded URLs

3. **Test on real devices**
   - Android: Chrome browser
   - iOS: Safari browser
   - Desktop: Chrome/Edge

4. **Update cache version**
   - Edit `/sw.js`
   - Change `CACHE_NAME` version
   - Forces cache refresh

## 📊 Browser Support

| Browser | Desktop | Mobile | Install Prompt | Offline | Push Notifications |
|---------|---------|--------|----------------|---------|-------------------|
| Chrome | ✅ | ✅ | ✅ | ✅ | ✅ |
| Edge | ✅ | ✅ | ✅ | ✅ | ✅ |
| Safari | ⚠️ | ⚠️ | ❌ (manual) | ⚠️ | ❌ |
| Firefox | ⚠️ | ❌ | ❌ | ✅ | ✅ |
| Opera | ✅ | ✅ | ✅ | ✅ | ✅ |

## 🛠️ Advanced Configuration

### Update Service Worker Cache

Edit `public/sw.js` and increment version:

```javascript
const CACHE_NAME = 'hms-v2.1'; // Increment version
```

### Add More Cached Routes

Edit `public/sw.js`:

```javascript
const STATIC_ASSETS = [
    '/',
    '/dashboard',
    '/pos',
    '/your-new-route', // Add here
];
```

### Customize Manifest

Edit `public/site.webmanifest`:

```json
{
    "name": "Your Custom Name",
    "theme_color": "#your-color",
    ...
}
```

## 📝 Useful Commands

```bash
# Rebuild assets after changes
npm run build

# Run tests
php artisan test --filter=PwaTest

# Clear application cache
php artisan cache:clear
php artisan config:clear
php artisan route:clear

# Check routes
php artisan route:list | grep pwa
```

## 🔗 Helpful Links

- **Test Page:** `/pwa-test`
- **Manifest:** `/site.webmanifest`
- **Service Worker:** `/sw.js`
- **Offline Page:** `/offline.html`

## ⚡ Quick Test

1. Visit: `http://your-domain.test/pwa-test`
2. Check all green checkmarks ✓
3. Click "Install HMS App"
4. Done! 🎉

## 🆘 Still Need Help?

If you're still experiencing issues:

1. Check browser console (F12) for error messages
2. Visit `/pwa-test` and share the status
3. Try in Chrome/Edge (best PWA support)
4. Clear cache and reload
5. Verify HTTPS in production

---

**Your HMS app is now PWA-ready! 🚀**
