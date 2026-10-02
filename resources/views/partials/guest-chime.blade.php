{{-- Order chime for the guest-order queues (Phase 3, D16). A generated
     two-note tone (no audio file to download). Browsers keep sound off
     until someone taps the page, so callers show "Tap to enable order
     sounds" until window.hmsChime.enabled() is true. Defined once even if
     the partial is included twice. --}}
<script>
    window.hmsChime = window.hmsChime || (function () {
        let ctx = null;

        function context() {
            if (!ctx) {
                const AudioCtx = window.AudioContext || window.webkitAudioContext;
                if (!AudioCtx) return null;
                ctx = new AudioCtx();
            }
            return ctx;
        }

        return {
            enable() {
                const c = context();
                if (c && c.state === 'suspended') c.resume();
                return this.enabled();
            },
            enabled() {
                return !!ctx && ctx.state === 'running';
            },
            play(loud = false) {
                const c = context();
                if (!c || c.state !== 'running') return false;
                [[0, 784], [0.18, 1046], ...(loud ? [[0.36, 1318]] : [])].forEach(([at, freq]) => {
                    const osc = c.createOscillator();
                    const gain = c.createGain();
                    osc.frequency.value = freq;
                    gain.gain.setValueAtTime(0.0001, c.currentTime + at);
                    gain.gain.exponentialRampToValueAtTime(loud ? 0.9 : 0.35, c.currentTime + at + 0.02);
                    gain.gain.exponentialRampToValueAtTime(0.0001, c.currentTime + at + 0.4);
                    osc.connect(gain).connect(c.destination);
                    osc.start(c.currentTime + at);
                    osc.stop(c.currentTime + at + 0.45);
                });
                return true;
            },
        };
    })();
</script>
