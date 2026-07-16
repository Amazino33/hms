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
--}}
<script>
    document.addEventListener('livewire:init', () => {
        Livewire.hook('request', ({ fail }) => {
            fail(({ status, content, preventDefault }) => {
                console.error('Livewire request failed', { status, content });

                if (status === 419) {
                    window.hmsShowSessionExpiredBanner();
                } else {
                    // Covers both real 5xx responses and a genuine network/
                    // connection failure, where Livewire reports no usable
                    // status at all.
                    window.hmsShowFailureToast();
                }

                preventDefault();
            });
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

    window.hmsShowFailureToast = function () {
        // Persistent (no auto-dismiss timer) — tap anywhere on the card to
        // dismiss. Not de-duplicated: a second distinct failure while one
        // toast is already showing still deserves its own visible toast,
        // stacked below the first.
        const existing = document.querySelectorAll('.hms-failure-toast').length;

        const toast = document.createElement('div');
        toast.className = 'hms-failure-toast';
        toast.setAttribute('role', 'alert');
        toast.style.cssText = [
            'position:fixed', `top:${16 + existing * 72}px`, 'left:50%', 'transform:translateX(-50%)',
            'z-index:99999', 'min-height:64px', 'display:flex', 'align-items:center',
            'background:#dc2626', 'color:#fff', 'padding:16px 24px', 'border-radius:12px',
            'font-weight:700', 'max-width:90vw', 'text-align:center', 'cursor:pointer',
            'box-shadow:0 10px 25px rgba(0,0,0,.35)',
        ].join(';');
        toast.textContent = 'Action failed — please try again. If this repeats, tell the manager.';
        toast.addEventListener('click', () => toast.remove());
        document.body.appendChild(toast);
    };
</script>
