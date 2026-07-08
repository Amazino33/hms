<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title>@yield('title', 'Kiosk')</title>
    @vite(['resources/css/app.css'])
    {{-- Notification::make()->send() toasts are Filament's, not a generic
         Livewire one — without this, <livewire:notifications /> renders
         but its Alpine component (notificationComponent) is never
         registered, so every notification silently fails to display
         instead of erroring loudly. This bit us in production: a real
         "you must start a shift" rejection looked like the Order button
         did nothing at all. --}}
    @filamentStyles
    {{-- The kiosk order screen has several modals (guest, cancel, return,
         cash drop) at z-[60], above Filament's default z-50 notification
         layer — without this, a notification fired while any of those
         modals is open renders invisibly behind it. --}}
    <style>.fi-no { z-index: 9999 !important; }</style>
</head>
<body class="bg-gray-900">
    @yield('content')

    {{-- The idle/table-grid screen never loads the pos component, but its
         per-table "Print Bill" action still needs window.printPOSBill —
         duplicated here rather than @include'd (an @include of this exact
         script, oddly, tripped Livewire's dev-mode "multiple root element"
         mount check across unrelated pos.blade.php tests — content-for-
         content identical markup passes fine when inlined directly).

         Built via DOM APIs rather than a single win.document.write() of a
         full "<html>...<body>...</body></html>" string — that string used
         to contain its own literal </body></html>, and something in the
         response pipeline (Livewire's own script/asset injection scans
         rendered HTML for a </body> to insert into) matched THAT
         occurrence instead of the real page's, splicing a <script> tag
         into the middle of this one and breaking it, so everything after
         the split point rendered as plain visible text instead of running
         as JS. Avoiding the literal tag text entirely removes the hazard
         regardless of the exact injection mechanism. --}}
    <script>
        window.printPOSBill = function printPOSBill(d) {
            const win = window.open('', '_blank', 'width=440,height=680,scrollbars=yes,resizable=yes');
            if (!win) { alert('Please allow pop-ups to print the bill.'); return; }

            const rows = (d.items || []).map(i =>
                `<tr><td style="padding:3px 6px;">${i.name}</td><td style="text-align:center;padding:3px 6px;">${i.quantity}</td><td style="text-align:right;padding:3px 6px;">&#8358;${Number(i.price * i.quantity).toLocaleString()}</td></tr>`
            ).join('');

            win.document.title = 'Unpaid Bill – ' + d.tableName;

            const style = win.document.createElement('style');
            style.textContent = `
                * { margin:0; padding:0; box-sizing:border-box; }
                body { font-family: 'Courier New', monospace; font-size:13px; width:80mm; padding:10px; color:#000; }
                h1 { text-align:center; font-size:16px; letter-spacing:2px; margin-bottom:2px; }
                .sub { text-align:center; font-size:11px; margin-bottom:4px; }
                .dashed { border-top:1px dashed #000; margin:6px 0; }
                .meta { font-size:12px; margin-bottom:2px; }
                table { width:100%; border-collapse:collapse; }
                th { text-align:left; font-size:11px; border-bottom:1px solid #000; padding:2px 6px; }
                .total-row { font-size:15px; font-weight:bold; text-align:right; margin-top:8px; }
                .footer { text-align:center; font-size:10px; margin-top:10px; color:#555; }
                @media print {
                    body { width:auto; }
                    button { display:none; }
                }
            `;
            win.document.head.appendChild(style);

            win.document.body.innerHTML = `
                <h1>HMS RECEIPT</h1>
                <div class="sub">*** UNPAID BILL ***</div>
                <div class="dashed"></div>
                <div class="meta">Table : <strong>${d.tableName}</strong></div>
                <div class="meta">Date  : ${d.date}</div>
                <div class="meta">Staff : ${d.cashier}</div>
                <div class="dashed"></div>
                <table>
                    <thead><tr><th>Item</th><th style="text-align:center;">Qty</th><th style="text-align:right;">Amount</th></tr></thead>
                    <tbody>${rows}</tbody>
                </table>
                <div class="dashed"></div>
                <div class="total-row">TOTAL: &#8358;${Number(d.total).toLocaleString()}</div>
                <div class="dashed"></div>
                <div class="footer">Thank you for dining with us!<br>This is NOT a payment receipt.</div>
            `;

            win.focus();
            setTimeout(() => { win.print(); }, 600);
        }
    </script>

    <livewire:notifications />
    {{-- @filamentScripts(withCore: true) already bundles and boots
         Livewire's core JS — Filament's own panel layout never also calls
         @livewireScripts alongside it. Having both loaded Livewire twice,
         and the second init stomped on the first, breaking Filament's
         notification component with "e is not a function" the moment it
         tried to render a toast. --}}
    @filamentScripts(withCore: true)
</body>
</html>
