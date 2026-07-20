<?php
// Deliberately plain PHP + defensive try/catch, not a normal Blade
// @php block with model calls left to fail loudly — this view can be
// asked to render at the exact moment migrations are running (the
// realistic reason maintenance mode is on in the first place), so a
// broken/mid-migration companies table must fall back to a generic
// message instead of turning the maintenance page itself into a 500.
$message = "We're making some improvements. Hang tight, we'll be back shortly.";
$targetEndsAtIso = null;

try {
    $company = \App\Models\Company::find(1);

    if ($company) {
        if (! empty($company->maintenance_message)) {
            $message = $company->maintenance_message;
        }

        $startedAt = $company->maintenance_started_at ?? now();
        $durationMinutes = $company->maintenance_duration_minutes ?? 15;
        $targetEndsAtIso = \Illuminate\Support\Carbon::parse($startedAt)
            ->addMinutes($durationMinutes)
            ->toIso8601String();
    }
} catch (\Throwable $e) {
    // Keep the generic defaults above — this page must render no matter
    // what state the database is in.
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Under Maintenance</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            padding: 24px;
        }
        .card {
            max-width: 480px;
            width: 100%;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 16px;
            padding: 40px 32px;
            text-align: center;
            box-shadow: 0 20px 40px rgba(0,0,0,.35);
        }
        .icon { font-size: 48px; margin-bottom: 8px; }
        h1 { font-size: 22px; margin: 0 0 12px; color: #f8fafc; }
        p.message { color: #cbd5e1; line-height: 1.5; margin: 0 0 28px; }
        .stats {
            display: flex;
            gap: 12px;
            margin-bottom: 28px;
        }
        .stat {
            flex: 1;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 10px;
            padding: 12px 8px;
        }
        .stat-label { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #94a3b8; margin-bottom: 4px; }
        .stat-value { font-size: 18px; font-weight: 700; color: #f8fafc; font-variant-numeric: tabular-nums; }
        button.retry {
            appearance: none;
            border: none;
            background: #4f46e5;
            color: #fff;
            font-size: 15px;
            font-weight: 600;
            padding: 12px 28px;
            border-radius: 10px;
            cursor: pointer;
        }
        button.retry:hover { background: #4338ca; }
        .checking { font-size: 12px; color: #64748b; margin-top: 14px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">🛠️</div>
        <h1>Under Maintenance</h1>
        <p class="message">{{ $message }}</p>

        <div class="stats">
            <div class="stat">
                <div class="stat-label">Down for</div>
                <div class="stat-value" id="elapsed">00:00</div>
            </div>
            <div class="stat">
                <div class="stat-label">Expected in</div>
                <div class="stat-value" id="remaining">—</div>
            </div>
        </div>

        <button class="retry" onclick="window.location.reload()">Try Again Now</button>
        <div class="checking" id="checking-text"></div>
    </div>

    <script>
        (function () {
            var pageLoadedAt = Date.now();
            var targetEndsAt = @json($targetEndsAtIso);
            var targetMs = targetEndsAt ? new Date(targetEndsAt).getTime() : null;

            function pad(n) { return String(n).padStart(2, '0'); }

            function formatDuration(totalSeconds) {
                totalSeconds = Math.max(0, Math.round(totalSeconds));
                var h = Math.floor(totalSeconds / 3600);
                var m = Math.floor((totalSeconds % 3600) / 60);
                var s = totalSeconds % 60;
                return h > 0 ? (pad(h) + ':' + pad(m) + ':' + pad(s)) : (pad(m) + ':' + pad(s));
            }

            function tick() {
                var elapsedSeconds = (Date.now() - pageLoadedAt) / 1000;
                document.getElementById('elapsed').textContent = formatDuration(elapsedSeconds);

                var remainingEl = document.getElementById('remaining');
                if (targetMs === null) {
                    remainingEl.textContent = '—';
                } else {
                    var remainingSeconds = (targetMs - Date.now()) / 1000;
                    remainingEl.textContent = remainingSeconds > 0
                        ? formatDuration(remainingSeconds)
                        : 'any moment now';
                }
            }

            tick();
            setInterval(tick, 1000);

            // Auto-retry in the background — the moment the server stops
            // returning 503, reload for real instead of making someone
            // remember to keep tapping the button.
            var retrySeconds = 10;
            var countdown = retrySeconds;
            var checkingEl = document.getElementById('checking-text');

            function updateCheckingText() {
                checkingEl.textContent = 'Automatically checking again in ' + countdown + 's…';
            }

            updateCheckingText();

            setInterval(function () {
                countdown -= 1;

                if (countdown <= 0) {
                    countdown = retrySeconds;

                    fetch(window.location.href, { method: 'HEAD', cache: 'no-store' })
                        .then(function (response) {
                            if (response.status !== 503) {
                                window.location.reload();
                            }
                        })
                        .catch(function () {
                            // Still unreachable — just wait for the next tick.
                        });
                }

                updateCheckingText();
            }, 1000);
        })();
    </script>
</body>
</html>
