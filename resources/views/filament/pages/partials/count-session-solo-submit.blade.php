{{--
    The solo count's single-PIN close: one signature, the counter's own —
    confirming her PIN commits the count immediately (stock reconciled,
    discrepancies created for any variance). No second person, no witness.
--}}
<x-mobile.pin-keypad
    title="Confirm and Submit"
    subtitle="Enter your PIN to seal this count. This can't be undone."
    onComplete="$wire.submitSoloCount(pin)" />
