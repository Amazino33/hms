/*
 * Guest QR menu (Phases 2–5; Phase 7A layout). Alpine only — no Livewire,
 * no session (D9). The whole menu arrives embedded in the page; tabs,
 * search, the cart, the split calculator and copy-to-clipboard all run
 * here. The server is asked for: availability (every 60 s), a request
 * submit, this phone's requests (every 8 s while one is live), the live
 * bill (8 s while a bill/call sheet is open and something can still
 * change, 30 s otherwise, never once the table is closed), Another round,
 * "I've paid" claims and waiter calls. The phone is known by its httpOnly
 * device cookie, which the browser sends by itself.
 *
 * Phase 7A (D31): every panel is a bottom sheet. Opening one pushes a
 * history entry so the phone's back button closes it; dragging the grabber
 * down more than 80 px closes it too.
 */
import Alpine from 'alpinejs';

const naira = (n) => '₦' + Math.round(n || 0).toLocaleString('en-NG');
const fold = (s) => (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const json = { 'Content-Type': 'application/json', Accept: 'application/json' };

Alpine.data('guestMenu', () => ({
    boot: JSON.parse(document.getElementById('guest-boot').textContent),
    tab: 'drinks',
    search: '',
    unavailable: new Set(),
    cart: [],
    sheet: null, // 'item' | 'search' | 'cart' | 'sent' | 'bill' | 'split' | 'pay' | 'call'
    current: null,
    picked: {},
    note: '',
    qty: 1,
    sending: false,
    sentRequest: null,
    requests: [],
    toast: null,
    pollTimer: null,
    activeSection: null,

    // Phase 4/5
    tableState: { state: 'none' },
    bill: null,
    billTimer: null,
    accounts: [],
    paidOpen: false,
    loadingRound: false,
    splitMode: 'equal',
    people: 2,
    picks: {},
    splitAmount: 0,
    claimName: '',
    claimAmount: null,
    claimAccount: '',
    claiming: false,
    copied: null,
    calling: false,
    callSent: false,
    otherOpen: false,
    callNote: '',
    billMessage: null,
    whatsappUrl: null,
    whatsappSlow: false,

    init() {
        this.unavailable = new Set(this.boot.unavailable);
        this.tab = this.boot.menu.tabs.drinks.length ? 'drinks' : 'food';
        this.cart = this.readCart();
        this.accounts = this.boot.accounts || [];
        this.applyTableState(this.boot.table || { state: 'none' });
        try { this.claimName = localStorage.getItem('gq_payer') || ''; } catch (e) { /* private mode */ }

        // Sheets and the back button (D31).
        try { history.replaceState({ sheet: null }, ''); } catch (e) { /* old browser */ }
        window.addEventListener('popstate', (e) => { this.sheet = e.state?.sheet || null; });

        if (this.canOrder) {
            this.refreshRequests();
            this.refreshBill();
        }

        if (this.boot.token) {
            setInterval(() => this.refreshAvailability(), 60000);
        }

        this.$watch('splitMode', () => this.updateSplit());
        this.$watch('people', () => this.updateSplit());
        this.$watch('picks', () => this.updateSplit(), { deep: true });
        this.$watch('tab', () => this.$nextTick(() => this.watchSections()));

        this.$nextTick(() => {
            this.watchSections();
            this.announceReady();
        });
    },

    get canOrder() { return this.boot.mode === 'order'; },
    get isRoom() { return this.boot.kind === 'room'; },
    naira,

    // ---- Splash hand-off (D32) -----------------------------------------
    // "Ready" = the menu is drawn and the first screen's photos have loaded
    // (or failed). The splash waits for this, never longer than 2 s.
    announceReady() {
        const firstScreen = window.innerHeight * 1.2;
        const images = [...document.querySelectorAll('#menu-list img, .popular img')]
            .filter((img) => img.getBoundingClientRect().top < firstScreen);
        const loaded = images.map((img) => (img.complete ? Promise.resolve() : new Promise((done) => {
            img.addEventListener('load', done, { once: true });
            img.addEventListener('error', done, { once: true });
        })));
        Promise.all(loaded).then(() => window.dispatchEvent(new Event('guest-ready')));
    },

    // ---- Sheets ---------------------------------------------------------
    // One history entry per open stack: a sheet opened from another sheet
    // replaces it, so Back always closes to the menu.
    openSheet(name) {
        if (name === 'bill' || name === 'call') this.refreshBill();
        try {
            if (this.sheet) {
                history.replaceState({ sheet: name }, '');
            } else {
                history.pushState({ sheet: name }, '');
            }
        } catch (e) { /* old browser */ }
        this.sheet = name;
        this.$nextTick(() => {
            const el = document.querySelector(`[data-sheet="${name}"]`);
            if (!el) return;
            el.style.transform = '';
            const target = el.querySelector('[data-autofocus]') || el;
            setTimeout(() => target.focus({ preventScroll: true }), 60);
        });
    },

    closeSheet() {
        if (!this.sheet) return;
        if (history.state && history.state.sheet) {
            history.back(); // popstate clears the sheet
        } else {
            this.sheet = null;
        }
    },

    // Drag the grabber down to close (more than 80 px).
    dragStart(e) { this._dragY = e.touches[0].clientY; this._dragEl = e.currentTarget.closest('.sheet'); this._dy = 0; },
    dragMove(e) {
        if (this._dragY == null || !this._dragEl) return;
        this._dy = Math.max(0, e.touches[0].clientY - this._dragY);
        this._dragEl.style.transition = 'none';
        this._dragEl.style.transform = `translateY(${this._dy}px)`;
    },
    dragEnd() {
        const el = this._dragEl;
        if (!el) return;
        el.style.transition = '';
        if (this._dy > 80) {
            this.closeSheet();
            setTimeout(() => { el.style.transform = ''; }, 260);
        } else {
            el.style.transform = '';
        }
        this._dragY = null;
        this._dragEl = null;
    },

    // ---- Menu --------------------------------------------------------
    get sections() {
        return this.boot.menu.tabs[this.tab]
            .map((section) => ({ ...section, items: section.items.filter((item) => !this.unavailable.has(item.key)) }))
            .filter((section) => section.items.length);
    },

    get searchResults() {
        const term = fold(this.search.trim());
        if (term.length < 2) return [];
        return ['drinks', 'food'].flatMap((tab) => this.boot.menu.tabs[tab])
            .flatMap((section) => section.items)
            .filter((item) => !this.unavailable.has(item.key) && (fold(item.name).includes(term) || fold(item.description).includes(term)));
    },

    get popular() {
        const items = this.allItems();
        return this.boot.popular.map((key) => items[key]).filter((item) => item && !this.unavailable.has(item.key));
    },

    allItems() {
        if (!this._items) {
            this._items = {};
            ['drinks', 'food'].forEach((tab) => this.boot.menu.tabs[tab].forEach((s) => s.items.forEach((i) => { this._items[i.key] = i; })));
        }
        return this._items;
    },

    showTab(tab) {
        if (this.sheet) this.closeSheet();
        this.tab = tab;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    },

    scrollTo(slug) {
        this.activeSection = slug;
        document.getElementById(slug)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    },

    // Scrolling the list moves the active category pill.
    watchSections() {
        this._observer?.disconnect();
        this.activeSection = this.sections[0]?.slug || null;
        if (!('IntersectionObserver' in window)) return;
        this._observer = new IntersectionObserver((entries) => {
            const visible = entries.filter((e) => e.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
            if (visible.length) {
                this.activeSection = visible[0].target.id;
                const pill = document.querySelector('.cat-pill.on');
                pill?.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' });
            }
        }, { rootMargin: '0px 0px -65% 0px' });
        document.querySelectorAll('#menu-list .section-head').forEach((el) => this._observer.observe(el));
    },

    initial(name) { return (name || '?').trim().charAt(0).toUpperCase(); },

    statusClass(line) {
        if (/^(Removed|Cancelled|Unavailable)/.test(line.status_label || '')) return '';
        return ['Ready', 'Served', 'Delivered', 'Paid', 'Charged'].includes(line.status_label) ? 'done' : 'active';
    },

    async refreshAvailability() {
        try {
            const res = await fetch(`/m/${this.boot.token}/availability`, { headers: { Accept: 'application/json' } });
            if (!res.ok) return;
            const data = await res.json();
            this.unavailable = new Set(data.unavailable);
            const before = this.cart.length;
            this.cart = this.cart.filter((line) => !this.unavailable.has(line.key));
            if (this.cart.length !== before) {
                this.writeCart();
                this.flash('Something in your order just sold out — we\'ve removed it.', true);
            }
        } catch (e) { /* offline: keep what we have */ }
    },

    // ---- Item sheet ---------------------------------------------------
    open(item) {
        this.current = item;
        this.picked = {};
        this.note = '';
        this.qty = 1;
        this.openSheet('item');
    },

    // The round "+" on a row: straight into the cart, unless the item has
    // options to choose (e.g. Cold / Not cold) — then the sheet opens.
    quickAdd(item, button) {
        try { navigator.vibrate && navigator.vibrate(10); } catch (e) { /* not supported */ }
        button?.classList.add('pop');
        setTimeout(() => button?.classList.remove('pop'), 120);

        if (item.chips && item.chips.length) {
            this.open(item);
            return;
        }

        this.addLine({ key: item.key, type: item.type, id: item.id, name: item.name, price: item.price, qty: 1, chips: [], note: '' });
        this.writeCart();
        this.flash('Added');
    },

    isPicked(group, label) { return (this.picked[group.group] || []).includes(label); },

    pick(group, label) {
        const chosen = this.picked[group.group] || [];
        if (group.selection === 'single') {
            this.picked[group.group] = chosen.includes(label) ? [] : [label];
        } else {
            this.picked[group.group] = chosen.includes(label) ? chosen.filter((l) => l !== label) : [...chosen, label];
        }
    },

    addToCart() {
        const item = this.current;
        this.addLine({ key: item.key, type: item.type, id: item.id, name: item.name, price: item.price, qty: this.qty, chips: Object.values(this.picked).flat(), note: this.note.trim().slice(0, 100) });
        this.writeCart();
        this.closeSheet();
        this.flash(`${item.name} added`);
    },

    addLine(line) {
        const same = this.cart.find((l) => l.key === line.key && l.note === line.note && l.chips.join('|') === line.chips.join('|'));
        if (same) {
            same.qty = Math.min(20, same.qty + line.qty);
        } else {
            this.cart.push({ ...line, uid: Date.now() + Math.random() });
        }
    },

    // ---- Cart ---------------------------------------------------------
    get cartCount() { return this.cart.reduce((n, l) => n + l.qty, 0); },
    get cartTotal() { return this.cart.reduce((n, l) => n + l.qty * l.price, 0); },

    step(line, by) {
        line.qty = Math.max(1, Math.min(20, line.qty + by));
        this.writeCart();
    },

    remove(line) {
        this.cart = this.cart.filter((l) => l.uid !== line.uid);
        this.writeCart();
        if (!this.cart.length) this.closeSheet();
    },

    cartKey() { return 'gq_cart_' + (this.boot.token || 'menu'); },
    readCart() { try { return JSON.parse(localStorage.getItem(this.cartKey())) || []; } catch (e) { return []; } },
    writeCart() { try { localStorage.setItem(this.cartKey(), JSON.stringify(this.cart)); } catch (e) { /* private mode */ } },

    // channel (rooms, Phase 5): 'whatsapp' opens WhatsApp to reception after
    // the order is saved; 'none' just saves it.
    async send(channel = null) {
        if (this.sending || !this.cart.length) return;
        this.sending = true;

        try {
            const res = await fetch(`/m/${this.boot.token}/requests`, {
                method: 'POST',
                headers: json,
                body: JSON.stringify({ channel, lines: this.cart.map((l) => ({ type: l.type, id: l.id, qty: l.qty, chips: l.chips, note: l.note || null })) }),
            });
            const data = await res.json().catch(() => ({}));

            if (res.ok && data.ok) {
                this.cart = [];
                this.writeCart();
                this.sentRequest = data.request;
                this.openSheet('sent');
                this.whatsappUrl = data.whatsapp_url || null;
                this.whatsappSlow = false;
                if (this.whatsappUrl) {
                    // The confirmation stays behind; if WhatsApp hasn't taken
                    // over within 2 s, offer the link by hand.
                    setTimeout(() => { if (!document.hidden) this.whatsappSlow = true; }, 2000);
                    window.location.href = this.whatsappUrl;
                }
                this.refreshRequests();
                this.refreshBill();
                return;
            }

            if (data.code === 'unavailable' && data.item) {
                this.unavailable.add(data.item);
                this.cart = this.cart.filter((l) => l.key !== data.item);
                this.writeCart();
            }

            this.flash(data.message || (res.status === 429 ? 'Too many tries — please wait a minute and send again.' : 'We couldn\'t send your order. Please try again.'), true);
        } catch (e) {
            this.flash('No connection — your order is saved here. Try again in a moment.', true);
        } finally {
            this.sending = false;
        }
    },

    // ---- This phone's requests -----------------------------------------
    get hasPending() { return this.requests.some((r) => r.live); },

    async refreshRequests() {
        if (!this.canOrder) return;
        try {
            const res = await fetch(`/m/${this.boot.token}/requests`, { headers: { Accept: 'application/json' } });
            if (!res.ok) return;
            const data = await res.json();
            this.requests = data.requests;
            if (this.sentRequest) {
                this.sentRequest = this.requests.find((r) => r.ref === this.sentRequest.ref) || this.sentRequest;
            }
        } catch (e) { /* keep polling */ }
        this.schedulePoll();
    },

    // Poll every 8 s only while something is still waiting for a waiter.
    schedulePoll() {
        clearTimeout(this.pollTimer);
        if (this.hasPending) {
            this.pollTimer = setTimeout(() => this.refreshRequests(), 8000);
        }
    },

    async cancel(request) {
        try {
            const res = await fetch(`/m/${this.boot.token}/requests/${encodeURIComponent(request.ref)}/cancel`, { method: 'POST', headers: json, body: '{}' });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.ok) {
                this.flash('Order cancelled');
                if (this.sentRequest?.ref === request.ref) this.sentRequest = data.request;
            } else {
                this.flash(data.message || 'We couldn\'t cancel that order.', true);
            }
        } catch (e) {
            this.flash('No connection — please try again.', true);
        }
        this.refreshRequests();
        this.refreshBill();
    },

    // ---- Live bill (Phase 4) -------------------------------------------
    applyTableState(state) {
        const was = this.tableState.state;
        this.tableState = state;

        // Closed: forget this table's cart and cached state once per closed
        // sitting; the next order starts a new one.
        if (state.state === 'closed' && state.closed_ref) {
            let seen = null;
            try { seen = localStorage.getItem('gq_closed_' + this.boot.token); } catch (e) { /* private mode */ }
            if (seen !== state.closed_ref) {
                this.cart = [];
                this.writeCart();
                this.requests = [];
                this.bill = null;
                try { localStorage.setItem('gq_closed_' + this.boot.token, state.closed_ref); } catch (e) { /* private mode */ }
            }
        }

        if (was === 'open' && state.state !== 'open') this.bill = null;
    },

    async refreshBill() {
        if (!this.canOrder) return;
        clearTimeout(this.billTimer);
        try {
            const res = await fetch(`/m/${this.boot.token}/bill`, { headers: { Accept: 'application/json' } });
            if (res.ok) {
                const data = await res.json();
                this.applyTableState(data.table || { state: 'none' });
                this.bill = data.bill;
                this.billMessage = data.message && data.message !== 'No open bill' ? data.message : null;
            }
        } catch (e) { /* offline: keep what we have */ }
        this.scheduleBill();
    },

    get billOpen() { return ['bill', 'call', 'pay', 'split'].includes(this.sheet); },

    // 8 s while the bill (or a call) is on screen and something can still
    // change; 30 s otherwise while the table is open; never once closed.
    // Rooms ('room') keep a slow poll even before the bill shows, so it
    // appears once reception approves this phone's first order (D25).
    scheduleBill() {
        clearTimeout(this.billTimer);
        if (this.tableState.state === 'room') {
            const fast = this.bill?.live && this.billOpen;
            this.billTimer = setTimeout(() => this.refreshBill(), fast ? 8000 : 30000);
            return;
        }
        if (this.tableState.state !== 'open' || !this.bill) return;
        const fast = this.bill.live && this.billOpen;
        this.billTimer = setTimeout(() => this.refreshBill(), fast ? 8000 : 30000);
    },

    async anotherRound() {
        if (this.loadingRound) return;
        this.loadingRound = true;
        try {
            const res = await fetch(`/m/${this.boot.token}/round`, { headers: { Accept: 'application/json' } });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.ok) {
                this.flash(data.message || 'We couldn\'t load your last round.', true);
                return;
            }
            if (!data.lines.length) {
                this.flash(data.skipped.length ? 'Your last round is sold out right now.' : 'No drinks to repeat yet.', true);
                return;
            }
            data.lines.forEach((l) => this.addLine(l));
            this.writeCart();
            this.openSheet('cart');
            if (data.skipped.length) this.flash('Not available now: ' + data.skipped.join(', '), true);
        } catch (e) {
            this.flash('No connection — please try again.', true);
        } finally {
            this.loadingRound = false;
        }
    },

    // ---- Split (records nothing on the server) --------------------------
    openSplit() {
        this.picks = {};
        this.people = 2;
        this.updateSplit();
        this.openSheet('split');
    },

    get equalShare() {
        const total = Math.round(this.bill?.totals.remaining || 0);
        const each = Math.floor(total / this.people);
        return { each, extra: total - each * this.people };
    },

    get itemsShare() {
        return (this.bill?.sections.on_bill || []).reduce((sum, l, k) => sum + (this.picks[k] || 0) * l.price, 0);
    },

    updateSplit() {
        this.splitAmount = this.splitMode === 'equal' ? this.equalShare.each : this.itemsShare;
    },

    // ---- Pay by transfer + "I've paid" -----------------------------------
    openPay(amount) {
        this.claimAmount = Math.round(amount || 0) || null;
        this.claimAccount = this.accounts.length === 1 ? String(this.accounts[0].id) : '';
        this.openSheet('pay');
    },

    async copy(account) {
        try {
            await navigator.clipboard.writeText(account.number);
        } catch (e) {
            // Older phones: select a hidden field and use the old copy command.
            const field = document.createElement('textarea');
            field.value = account.number;
            field.setAttribute('readonly', '');
            field.style.position = 'fixed';
            field.style.opacity = '0';
            document.body.appendChild(field);
            field.select();
            field.setSelectionRange(0, 99);
            try { document.execCommand('copy'); } catch (err) { /* nothing more to try */ }
            field.remove();
        }
        this.copied = account.id;
        setTimeout(() => { if (this.copied === account.id) this.copied = null; }, 2000);
    },

    async sendClaim() {
        if (this.claiming) return;
        if (this.claimName.trim().length < 2) { this.flash('Enter the name on the account you paid from.', true); return; }
        if (!(this.claimAmount > 0)) { this.flash('Enter the amount you sent.', true); return; }
        this.claiming = true;
        try {
            const res = await fetch(`/m/${this.boot.token}/claims`, {
                method: 'POST',
                headers: json,
                body: JSON.stringify({ payer_name: this.claimName.trim(), amount: this.claimAmount, transfer_account_id: this.claimAccount || null }),
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.ok) {
                try { localStorage.setItem('gq_payer', this.claimName.trim()); } catch (e) { /* private mode */ }
                this.openSheet('bill');
                this.flash(this.isRoom ? 'Claim sent — reception will confirm.' : 'Claim sent — your waiter will confirm.');
                this.refreshBill();
            } else {
                this.flash(data.message || (res.status === 429 ? 'Please wait a few minutes before sending another.' : 'We couldn\'t send that. Please try again.'), true);
            }
        } catch (e) {
            this.flash('No connection — please try again.', true);
        } finally {
            this.claiming = false;
        }
    },

    async withdraw(claim) {
        try {
            const res = await fetch(`/m/${this.boot.token}/claims/${claim.id}/withdraw`, { method: 'POST', headers: json, body: '{}' });
            const data = await res.json().catch(() => ({}));
            this.flash(res.ok && data.ok ? 'Payment claim withdrawn' : (data.message || 'We couldn\'t withdraw that.'), !(res.ok && data.ok));
        } catch (e) {
            this.flash('No connection — please try again.', true);
        }
        this.refreshBill();
    },

    // ---- Call waiter / reception ------------------------------------------
    async callWaiter(reason) {
        if (this.calling) return;
        this.calling = true;
        try {
            const res = await fetch(`/m/${this.boot.token}/calls`, {
                method: 'POST',
                headers: json,
                body: JSON.stringify({ reason, note: reason === 'other' ? this.callNote.trim() : null }),
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.ok) {
                this.callSent = true;
                this.otherOpen = false;
                this.callNote = '';
                this.flash(this.isRoom ? 'Reception has been called' : 'Waiter has been called');
                this.refreshBill();
            } else {
                this.flash(data.message || (res.status === 429 ? 'You just called — please wait a moment.' : 'We couldn\'t reach anyone. Please try again.'), true);
            }
        } catch (e) {
            this.flash('No connection — please try again.', true);
        } finally {
            this.calling = false;
        }
    },

    flash(message, error = false) {
        this.toast = { message, error };
        clearTimeout(this._toastTimer);
        this._toastTimer = setTimeout(() => { this.toast = null; }, error ? 4000 : 2200);
    },
}));

Alpine.start();
