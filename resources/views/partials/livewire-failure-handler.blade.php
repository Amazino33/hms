{{--
    The system-wide fix for "the whole page goes dead": every layout
    (admin panel via AdminPanelProvider's render hook, every kiosk page via
    kiosk.blade.php) includes this exact same script. It's the one place
    that catches a Livewire request failure that no per-component guard
    could ever catch, because the guard's own catch block never runs — the
    response never arrived.

    Deliberately NOT routed through Filament's own Notification/livewire:notifications
    component: that system depends on a successful request/response round
    trip to reach the client, which is exactly what's failing here. This
    builds its own minimal DOM banner/toast instead.

    Four distinct messages, not one generic "action failed" — the whole
    point is telling someone what's actually wrong instead of always
    pointing at the manager:
    - 419: session/CSRF expired — existing banner, unchanged.
    - Genuinely offline (navigator.onLine is false): say so directly.
    - 503, or no HTTP status at all: "can't reach the server right now".
      Verified live (via a real aborted-connection test) that a dropped
      connection reports back as status 503, not 0/undefined — the
      browser (or a registered service worker sitting in front of the
      request) synthesizes 503 for a connection it couldn't complete.
      This is also the exact status Laravel's own maintenance mode
      returns during a deploy, which is a real, expected, temporary state,
      not a bug — both cases get the same "try again shortly" message,
      not "tell the manager".
    - Any other bad status (500, 502, 504, or anything else this list
      doesn't special-case): a genuine server-side message — this is the
      one case actually worth telling the manager about.
--}}
<script>
    document.addEventListener('livewire:init', () => {
        Livewire.hook('request', ({ fail }) => {
            fail(({ status, content, preventDefault }) => {
                console.error('Livewire request failed', { status, content });

                if (status === 419) {
                    window.hmsShowSessionExpiredBanner();
                } else if (!navigator.onLine) {
                    window.hmsShowFailureToast('offline');
                } else if (!status || status === 503) {
                    window.hmsShowFailureToast('unreachable');
                } else {
                    // A real response came back with a bad status (5xx, or
                    // anything else not already handled above) — this one
                    // is genuinely on us.
                    window.hmsShowFailureToast('server');
                }

                preventDefault();
            });
        });

        // If a failure toast is up for a connection-related reason and the
        // browser reports coming back online, let people know it's worth
        // trying again — the same "tell them the real state" idea, in
        // reverse.
        window.addEventListener('online', () => {
            const toast = document.getElementById('hms-failure-toast');
            if (toast && (toast.dataset.kind === 'offline' || toast.dataset.kind === 'unreachable')) {
                toast.querySelector('.hms-failure-toast-text').textContent = 'Back online — you can try again now.';
                toast.style.background = '#15803d';
                setTimeout(() => toast.remove(), 3000);
            }
        });
    });

    window.hmsShowSessionExpiredBanner = function () {
        if (document.getElementById('hms-session-expired-banner')) return;

        const banner = document.createElement('div');
        banner.id = 'hms-session-expired-banner';
        banner.setAttribute('role', 'alert');
        banner.style.cssText = [
            'position:fixed', 'inset:0 0 auto 0', 'z-index:99999',
            'min-height:64px', 'display:flex', 'align-items:center', 'justify-content:center',
            'background:#dc2626', 'color:#fff', 'padding:16px', 'text-align:center',
            'font-weight:700', 'font-size:16px', 'cursor:pointer',
            'box-shadow:0 4px 12px rgba(0,0,0,.3)',
        ].join(';');
        banner.textContent = 'Session expired — tap to reload';
        banner.addEventListener('click', () => window.location.reload());
        document.body.appendChild(banner);
    };

    window.hmsFailureMessages = {
        offline: "You're offline — check your connection. Your last action didn't go through.",
        unreachable: "Couldn't reach the server — check your connection and try again.",
        server: 'Something went wrong on our end. Please try again. If this repeats, tell the manager.',
    };

    window.hmsShowFailureToast = function (kind) {
        // Persistent (no auto-dismiss timer) — tap anywhere on the card to
        // dismiss. Deduplicated to a single toast: this app never does a
        // full page reload between screens, so on a stretch where the
        // server is genuinely struggling, un-deduplicated stacking buries
        // the whole screen under a wall of identical banners nobody can
        // act on. A repeat failure of the SAME kind while one is already
        // showing just bumps its own counter and gives it a brief pulse;
        // a DIFFERENT kind replaces the message and resets the count,
        // since a fresh problem showing up shouldn't be described by the
        // previous one's text.
        const message = window.hmsFailureMessages[kind] ?? window.hmsFailureMessages.server;
        const existingToast = document.getElementById('hms-failure-toast');

        if (existingToast) {
            const sameKind = existingToast.dataset.kind === kind;
            const count = sameKind ? parseInt(existingToast.dataset.count || '1', 10) + 1 : 1;
            existingToast.dataset.count = String(count);
            existingToast.dataset.kind = kind;
            existingToast.style.background = '#dc2626';
            existingToast.querySelector('.hms-failure-toast-text').textContent =
                count > 1 ? `${message} (${count}×)` : message;
            existingToast.style.transform = 'translateX(-50%) scale(1.05)';
            setTimeout(() => { existingToast.style.transform = 'translateX(-50%) scale(1)'; }, 150);
            return;
        }

        const toast = document.createElement('div');
        toast.id = 'hms-failure-toast';
        toast.className = 'hms-failure-toast';
        toast.setAttribute('role', 'alert');
        toast.dataset.count = '1';
        toast.dataset.kind = kind;
        toast.style.cssText = [
            'position:fixed', 'top:16px', 'left:50%', 'transform:translateX(-50%)',
            'z-index:99999', 'min-height:64px', 'display:flex', 'align-items:center',
            'background:#dc2626', 'color:#fff', 'padding:16px 24px', 'border-radius:12px',
            'font-weight:700', 'max-width:90vw', 'text-align:center', 'cursor:pointer',
            'box-shadow:0 10px 25px rgba(0,0,0,.35)', 'transition:transform 150ms ease, background 200ms ease',
        ].join(';');

        const text = document.createElement('span');
        text.className = 'hms-failure-toast-text';
        text.textContent = message;
        toast.appendChild(text);

        toast.addEventListener('click', () => toast.remove());
        document.body.appendChild(toast);
    };
</script>
