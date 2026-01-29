<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HMS PWA Test</title>
    <link rel="icon" href="/favicon.ico">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    <meta name="theme-color" content="#1f2937">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background: #f3f4f6;
        }
        .card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        h1 {
            color: #1f2937;
            margin-top: 0;
        }
        h2 {
            color: #374151;
            font-size: 1.2rem;
            margin-top: 0;
        }
        .status {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
        }
        .status-icon {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }
        .success {
            background: #10b981;
            color: white;
        }
        .error {
            background: #ef4444;
            color: white;
        }
        .warning {
            background: #f59e0b;
            color: white;
        }
        button {
            background: #3b82f6;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            margin-right: 8px;
            margin-bottom: 8px;
        }
        button:hover {
            background: #2563eb;
        }
        button:disabled {
            background: #9ca3af;
            cursor: not-allowed;
        }
        pre {
            background: #1f2937;
            color: #f3f4f6;
            padding: 16px;
            border-radius: 8px;
            overflow-x: auto;
            font-size: 14px;
        }
        .log {
            max-height: 300px;
            overflow-y: auto;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 12px;
            margin-top: 12px;
        }
        .log-entry {
            margin-bottom: 4px;
            font-family: monospace;
            font-size: 13px;
        }
        .back-link {
            display: inline-block;
            color: #3b82f6;
            text-decoration: none;
            margin-bottom: 20px;
        }
        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <a href="/admin" class="back-link">← Back to Admin</a>
    
    <div class="card">
        <h1>🚀 HMS PWA Installation Test</h1>
        <p>Use this page to test if your PWA is properly configured and installable.</p>
    </div>

    <div class="card">
        <h2>PWA Requirements Check</h2>
        <div id="requirements"></div>
    </div>

    <div class="card">
        <h2>Installation</h2>
        <button id="installBtn" disabled>Install HMS App</button>
        <button onclick="window.checkPWASupport()">Check PWA Support</button>
        <button onclick="manualRegisterSW()">Register Service Worker Manually</button>
        <button onclick="location.reload()">Reload Page</button>
        <div id="installStatus" style="margin-top: 12px;"></div>
    </div>

    <div class="card">
        <h2>Manifest Information</h2>
        <pre id="manifestInfo">Loading...</pre>
    </div>

    <div class="card">
        <h2>Service Worker Status</h2>
        <div id="swStatus"></div>
    </div>

    <div class="card">
        <h2>Console Log</h2>
        <div class="log" id="logContainer"></div>
    </div>

    <script>
        // Capture console logs
        const originalLog = console.log;
        const originalError = console.error;
        const originalWarn = console.warn;
        const logContainer = document.getElementById('logContainer');

        function addToLog(type, ...args) {
            const entry = document.createElement('div');
            entry.className = 'log-entry';
            entry.textContent = `[${type}] ${args.join(' ')}`;
            logContainer.appendChild(entry);
            logContainer.scrollTop = logContainer.scrollHeight;
        }

        console.log = (...args) => {
            originalLog(...args);
            addToLog('LOG', ...args);
        };

        console.error = (...args) => {
            originalError(...args);
            addToLog('ERROR', ...args);
        };

        console.warn = (...args) => {
            originalWarn(...args);
            addToLog('WARN', ...args);
        };

        // Check requirements
        function checkRequirements() {
            const requirements = document.getElementById('requirements');
            
            // Detailed browser detection
            const ua = navigator.userAgent;
            const browserInfo = {
                name: 'Unknown',
                version: 'Unknown',
                details: ua
            };
            
            if (ua.indexOf('Chrome') > -1 && ua.indexOf('Edg') === -1) {
                browserInfo.name = 'Chrome';
                browserInfo.version = ua.match(/Chrome\/(\d+)/)?.[1] || 'Unknown';
            } else if (ua.indexOf('Edg') > -1) {
                browserInfo.name = 'Edge';
                browserInfo.version = ua.match(/Edg\/(\d+)/)?.[1] || 'Unknown';
            } else if (ua.indexOf('Safari') > -1 && ua.indexOf('Chrome') === -1) {
                browserInfo.name = 'Safari';
                browserInfo.version = ua.match(/Version\/(\d+)/)?.[1] || 'Unknown';
            } else if (ua.indexOf('Firefox') > -1) {
                browserInfo.name = 'Firefox';
                browserInfo.version = ua.match(/Firefox\/(\d+)/)?.[1] || 'Unknown';
            }

            const isLocalhost = location.hostname === 'localhost' || 
                               location.hostname === '127.0.0.1' || 
                               location.hostname.includes('.test');
            
            const isHTTPS = location.protocol === 'https:';
            const isSecureContext = window.isSecureContext;
            
            const checks = [
                {
                    name: 'Browser',
                    passed: true,
                    message: `${browserInfo.name} ${browserInfo.version}`
                },
                {
                    name: 'Secure Context',
                    passed: isSecureContext,
                    message: isSecureContext ? '✓ Secure context available' : '✗ Not a secure context (HTTPS required or use localhost)'
                },
                {
                    name: 'HTTPS or Localhost',
                    passed: isHTTPS || isLocalhost,
                    message: isHTTPS ? 'Using HTTPS ✓' : (isLocalhost ? 'Using localhost (OK for development) ✓' : '✗ HTTPS required for production')
                },
                {
                    name: 'Service Worker API',
                    passed: 'serviceWorker' in navigator,
                    message: 'serviceWorker' in navigator ? 
                        '✓ Service Worker API available' : 
                        '✗ Service Worker API not available in this browser'
                },
                {
                    name: 'Service Worker Enabled',
                    passed: 'serviceWorker' in navigator,
                    message: 'serviceWorker' in navigator ? 
                        '✓ Enabled' : 
                        '✗ Disabled or not supported. Try: 1) Use Chrome/Edge, 2) Exit private/incognito mode, 3) Enable in browser settings'
                },
                {
                    name: 'Manifest Link',
                    passed: !!document.querySelector('link[rel="manifest"]'),
                    message: document.querySelector('link[rel="manifest"]') ? 
                        `✓ Found: ${document.querySelector('link[rel="manifest"]').href}` : 
                        '✗ Missing'
                },
                {
                    name: 'Install Prompt Support',
                    passed: 'BeforeInstallPromptEvent' in window || 'onbeforeinstallprompt' in window,
                    message: ('BeforeInstallPromptEvent' in window || 'onbeforeinstallprompt' in window) ? 
                        '✓ Supported' : 
                        '⚠️ Not supported (iOS Safari uses manual installation)'
                }
            ];

            requirements.innerHTML = checks.map(check => `
                <div class="status">
                    <div class="status-icon ${check.passed ? 'success' : (check.message.includes('⚠️') ? 'warning' : 'error')}">
                        ${check.passed ? '✓' : (check.message.includes('⚠️') ? '!' : '✗')}
                    </div>
                    <div>
                        <strong>${check.name}:</strong> ${check.message}
                    </div>
                </div>
            `).join('');
            
            // Add troubleshooting tips if service worker is not available
            if (!('serviceWorker' in navigator)) {
                requirements.innerHTML += `
                    <div style="background: #fef3c7; border: 1px solid #f59e0b; padding: 12px; border-radius: 8px; margin-top: 12px;">
                        <strong>⚠️ Service Worker Not Available</strong>
                        <p style="margin: 8px 0 0 0; font-size: 14px;">Possible solutions:</p>
                        <ul style="margin: 8px 0 0 0; font-size: 14px; padding-left: 20px;">
                            <li><strong>Use Chrome or Edge browser</strong> (best PWA support)</li>
                            <li><strong>Exit private/incognito mode</strong> - Service Workers are often disabled in private browsing</li>
                            <li><strong>Check browser settings</strong> - Ensure Service Workers aren't disabled</li>
                            <li><strong>Update your browser</strong> - Use the latest version</li>
                            <li><strong>Try a different device</strong> - Some corporate/school networks block Service Workers</li>
                        </ul>
                    </div>
                `;
            }
        }

        // Load manifest
        async function loadManifest() {
            try {
                const response = await fetch('/site.webmanifest');
                const manifest = await response.json();
                document.getElementById('manifestInfo').textContent = JSON.stringify(manifest, null, 2);
                console.log('Manifest loaded successfully:', manifest);
            } catch (error) {
                document.getElementById('manifestInfo').textContent = `Error loading manifest: ${error.message}`;
                console.error('Manifest error:', error);
            }
        }

        // Check service worker
        async function checkServiceWorker() {
            const swStatus = document.getElementById('swStatus');
            
            if (!('serviceWorker' in navigator)) {
                swStatus.innerHTML = `
                    <div class="status"><div class="status-icon error">✗</div><div>Service Worker not supported</div></div>
                    <div style="background: #fee; padding: 12px; border-radius: 8px; margin-top: 12px;">
                        <strong>Your browser doesn't support Service Workers.</strong><br>
                        Please use Chrome, Edge, or update your browser to the latest version.
                    </div>
                `;
                return;
            }

            try {
                const registration = await navigator.serviceWorker.getRegistration();
                
                if (registration) {
                    const state = registration.active ? 'Active' : 
                                 registration.installing ? 'Installing' : 
                                 registration.waiting ? 'Waiting' : 'Unknown';
                    
                    swStatus.innerHTML = `
                        <div class="status"><div class="status-icon success">✓</div><div><strong>Registered:</strong> ${registration.scope}</div></div>
                        <div class="status"><div class="status-icon success">✓</div><div><strong>State:</strong> ${state}</div></div>
                        ${registration.active ? '<div class="status"><div class="status-icon success">✓</div><div><strong>Status:</strong> Running and active</div></div>' : ''}
                    `;
                    console.log('Service Worker registered:', registration);
                } else {
                    swStatus.innerHTML = `
                        <div class="status"><div class="status-icon warning">!</div><div>No Service Worker registered yet</div></div>
                        <button onclick="manualRegisterSW()" style="margin-top: 8px;">Register Now</button>
                    `;
                }
            } catch (error) {
                swStatus.innerHTML = `<div class="status"><div class="status-icon error">✗</div><div>Error: ${error.message}</div></div>`;
                console.error('Service Worker check error:', error);
            }
        }

        // Manual service worker registration
        async function manualRegisterSW() {
            const swStatus = document.getElementById('swStatus');
            
            if (!('serviceWorker' in navigator)) {
                alert('Service Worker is not supported in your browser. Please use Chrome or Edge.');
                return;
            }
            
            try {
                swStatus.innerHTML = '<div class="status"><div class="status-icon warning">⌛</div><div>Registering Service Worker...</div></div>';
                
                const registration = await navigator.serviceWorker.register('/sw.js');
                console.log('Service Worker registered manually:', registration);
                
                swStatus.innerHTML = `
                    <div class="status"><div class="status-icon success">✓</div><div><strong>Successfully registered!</strong></div></div>
                    <div class="status"><div class="status-icon success">✓</div><div><strong>Scope:</strong> ${registration.scope}</div></div>
                `;
                
                // Refresh the status after a moment
                setTimeout(checkServiceWorker, 1000);
            } catch (error) {
                console.error('Service Worker registration failed:', error);
                swStatus.innerHTML = `
                    <div class="status"><div class="status-icon error">✗</div><div><strong>Registration failed:</strong> ${error.message}</div></div>
                    <div style="background: #fee; padding: 12px; border-radius: 8px; margin-top: 8px;">
                        <strong>Common solutions:</strong>
                        <ul style="margin: 8px 0 0 0; padding-left: 20px; font-size: 14px;">
                            <li>Use Chrome or Edge browser</li>
                            <li>Exit private/incognito mode</li>
                            <li>Clear browser cache and reload</li>
                            <li>Check browser console for errors (F12)</li>
                        </ul>
                    </div>
                `;
            }
        }

        // Install handler
        let deferredPrompt;
        const installBtn = document.getElementById('installBtn');
        const installStatus = document.getElementById('installStatus');

        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            installBtn.disabled = false;
            installStatus.innerHTML = '<div class="status"><div class="status-icon success">✓</div><div>App is installable!</div></div>';
            console.log('beforeinstallprompt event fired');
        });

        installBtn.addEventListener('click', async () => {
            if (!deferredPrompt) {
                installStatus.innerHTML = '<div class="status"><div class="status-icon error">✗</div><div>Install prompt not available</div></div>';
                return;
            }

            deferredPrompt.prompt();
            const { outcome } = await deferredPrompt.userChoice;
            
            if (outcome === 'accepted') {
                installStatus.innerHTML = '<div class="status"><div class="status-icon success">✓</div><div>Installation accepted!</div></div>';
                console.log('User accepted installation');
            } else {
                installStatus.innerHTML = '<div class="status"><div class="status-icon warning">!</div><div>Installation dismissed</div></div>';
                console.log('User dismissed installation');
            }
            
            deferredPrompt = null;
            installBtn.disabled = true;
        });

        window.addEventListener('appinstalled', () => {
            installStatus.innerHTML = '<div class="status"><div class="status-icon success">✓</div><div>App installed successfully!</div></div>';
            console.log('App installed');
        });

        // Check if already installed
        if (window.matchMedia('(display-mode: standalone)').matches) {
            installStatus.innerHTML = '<div class="status"><div class="status-icon success">✓</div><div>App is already installed and running in standalone mode</div></div>';
            installBtn.disabled = true;
            installBtn.textContent = 'Already Installed';
        }

        // Initialize
        checkRequirements();
        loadManifest();
        checkServiceWorker();

        // Service worker registration is in app.js, but we can monitor it
        console.log('PWA Test Page Loaded');
        console.log('Current URL:', window.location.href);
        console.log('Protocol:', window.location.protocol);
    </script>

    @vite(['resources/js/app.js'])
</body>
</html>
