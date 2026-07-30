<?php

/**
 * The "Create Transfer" form's submit handler used to be attached directly
 * to the #transfer-form element found at script-run time — every other
 * handler in this same file (click/change/input) is delegated via
 * document.addEventListener(). wire:init="load" (the deferred "Recent
 * Transfers" section) fires a Livewire re-render that doesn't know about
 * the item rows this script injects client-side, so it can replace the
 * form node rather than patch it, silently dropping a directly-attached
 * listener. The next click then falls through to a real native form
 * submission straight to /stock-transfers with a stale CSRF token,
 * landing the storekeeper on Laravel's raw 419 error page instead of the
 * app's own UI. A listener on document survives any re-render.
 */
it('delegates the transfer form submit handler via document, not a direct node reference', function () {
    $view = file_get_contents(resource_path('views/filament/pages/storekeeper-transfers.blade.php'));

    expect($view)->not->toContain("getElementById('transfer-form').addEventListener('submit'");
    expect($view)->toContain("document.addEventListener('submit', function (e) {");
    expect($view)->toContain("e.target.id !== 'transfer-form'");
});

it('shows a clear session-expired message instead of a generic error on a real 419 response', function () {
    $view = file_get_contents(resource_path('views/filament/pages/storekeeper-transfers.blade.php'));

    expect($view)->toContain('response.status === 419');
    expect($view)->toContain('SESSION_EXPIRED');
});

/**
 * Production incident: a storekeeper's single transfer got created 4
 * times with identical line items, all at the same timestamp — nothing
 * stopped a repeated/rapid click on "Create Transfer" from firing this
 * whole handler (including another full POST to /stock-transfers) again
 * before the first request's response came back. The submit button is
 * now the guard: disabled the instant a valid submission starts sending,
 * re-enabled only on an error path (never on success, since the page
 * reloads shortly after anyway) — plus an explicit early-return in case
 * some path ever re-enables it while a request is still in flight.
 */
it('disables the submit button while a transfer request is in flight, and only re-enables it on an error path', function () {
    $view = file_get_contents(resource_path('views/filament/pages/storekeeper-transfers.blade.php'));

    expect($view)->toContain('id="transfer-submit-btn"');
    expect($view)->toContain("if (submitBtn && submitBtn.disabled) return;");
    expect($view)->toContain('submitBtn.disabled = true;');
    expect($view)->toContain('function reenableSubmitBtn()');
});
