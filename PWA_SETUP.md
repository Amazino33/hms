# HMS PWA Development Setup

## HTTPS Setup for PWA Development

### Option 1: Use localhost instead of hms.test
1. Update your hosts file to point hms.test to 127.0.0.1
2. Or use `php artisan serve --host=127.0.0.1 --port=8000`
3. Access via: http://127.0.0.1:8000 or http://localhost:8000

### Option 2: Set up HTTPS locally with mkcert
1. Install mkcert: `choco install mkcert` (Windows) or `brew install mkcert` (Mac)
2. Install CA: `mkcert -install`
3. Generate certificate: `mkcert hms.test`
4. Configure your web server to use the certificates

### Option 3: Browser Workaround (Chrome)
1. Open Chrome with flags: `chrome.exe --unsafely-treat-insecure-origin-as-secure=http://hms.test`
2. Or visit `chrome://flags/#unsafely-treat-insecure-origin-as-secure` and add `http://hms.test`

## Testing PWA Features

1. Visit your app on HTTPS/localhost
2. Open DevTools → Application tab
3. Check Manifest section - should show "Manifest parsed successfully"
4. Check Service Workers section - should show registered service worker
5. Interact with the app (click buttons, etc.)
6. Look for install prompt or check console for PWA events