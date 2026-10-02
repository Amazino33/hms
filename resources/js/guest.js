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
 *
 * Phase 7C (D34–D38): sticky top bar with search, first-visit hints, the
 * "Order sent" moment and status strip, inline row steppers, and the
 * owner-set selling features. Every cart line remembers how it was added
 * (`via`, sent as added_via) — a label for the owner's reports, nothing more.
 */
import Alpine from 'alpinejs';

const naira = (n) => '₦' + Math.round(n || 0).toLocaleString('en-NG');
const fold = (s) => (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const json = { 'Content-Type': 'application/json', Accept: 'application/json' };
const store = {
    get(key) { try { return localStorage.getItem(key); } catch (e) { return null; } },
    set(key, value) { try { localStorage.setItem(key, value); } catch (e) { /* private mode */ } },
};
const reducedMotion = () => window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const BADGES = { chefs_special: 'Chef\'s special', bestseller: 'Bestseller', new: 'New', spicy: 'Spicy' };
const FINAL = ['ready', 'delivered'];

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

    // Phase 7C
    currentVia: 'menu',
    hints: false,
    hintTip: false,
    placeholderIdx: 0,
    pairFor: null,
    sentMoment: false,
    latestStatus: null,
    acceptedBy: null,
    finalSince: null,
    lastRound: null,
    nudgeSeen: null,
    againDismissed: false,
    closedDismissed: false,
    now: Date.now(),

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

        this.watchKeyboard();
        this.watchTopbar();
        this.startHints();
        this.againDismissed = store.get(this.againKey()) === (this.boot.last_visit?.summary || '');
        // One clock for the countdown, the status strip timeout and the nudge.
        setInterval(() => { this.now = Date.now(); }, 30000);
    },

    // Sticky section titles sit just under the sticky top bar (D34).
    watchTopbar() {
        const bar = document.querySelector('.topbar');
        if (!bar) return;
        const set = () => document.documentElement.style.setProperty('--top', bar.offsetHeight + 'px');
        set();
        if ('ResizeObserver' in window) new ResizeObserver(set).observe(bar);
    },

    // ---- First-visit hints (D35) -------------------------------------------
    // Once per device: the place pill glows with a tooltip (6 s), and the
    // search placeholder cycles real item names. The first touch ends both.
    startHints() {
        if (store.get('selum_hints_seen') || !this.boot.place) return;
        this.hints = true;
        this.hintTip = true;
        store.set('selum_hints_seen', '1');
        const stop = () => {
            this.hints = false;
            this.hintTip = false;
            clearInterval(this._hintCycle);
            document.removeEventListener('pointerdown', stop, true);
        };
        document.addEventListener('pointerdown', stop, true);
        setTimeout(() => { this.hintTip = false; }, 6000);
        if ((this.boot.hint_items || []).length > 1 && !reducedMotion()) {
            this._hintCycle = setInterval(() => {
                this.placeholderIdx = (this.placeholderIdx + 1) % this.boot.hint_items.length;
            }, 2600);
        }
    },

    get placeholder() {
        const names = this.boot.hint_items || [];
        return this.hints && names.length ? `Search "${names[this.placeholderIdx % names.length]}"…` : 'Search the menu';
    },

    // ---- Keyboard (iOS + older Android) ------------------------------------
    // The keyboard overlays fixed elements instead of shrinking the page.
    // --kb is how much of the screen it covers, so sheets can ride above it;
    // html.kb-open hides the bottom nav and cart bar while it is up.
    watchKeyboard() {
        const root = document.documentElement;
        const vv = window.visualViewport;
        let tallest = window.innerHeight;
        const typing = () => {
            const el = document.activeElement;
            return !!el && (el.tagName === 'TEXTAREA' || el.tagName === 'SELECT' || (el.tagName === 'INPUT' && !['checkbox', 'radio', 'button'].includes(el.type)));
        };
        const update = () => {
            tallest = Math.max(tallest, window.innerHeight);
            const kb = vv ? Math.max(0, window.innerHeight - vv.height - vv.offsetTop) : 0;
            root.style.setProperty('--kb', kb + 'px');
            // Android with interactive-widget=resizes-content shrinks the
            // page instead — a much shorter window while typing is the keyboard.
            root.classList.toggle('kb-open', kb > 80 || (typing() && tallest - window.innerHeight > 150));
        };
        if (vv) {
            vv.addEventListener('resize', update);
            vv.addEventListener('scroll', update);
        }
        window.addEventListener('resize', update);
        document.addEventListener('focusin', (e) => {
            update();
            // Never leave the field being typed in under the keyboard.
            if (e.target.closest && e.target.closest('.sheet') && typing()) {
                setTimeout(() => e.target.scrollIntoView({ block: 'center' }), 100);
            }
        });
        document.addEventListener('focusout', () => setTimeout(update, 50));
        update();
    },

    // Search opens AND focuses inside the user's own tap — iOS only raises
    // the keyboard for a focus that happens synchronously in the tap.
    openSearch() {
        const panel = this.$refs.searchPanel;
        panel.classList.add('open');
        this.$refs.searchInput.focus({ preventScroll: true });
        this.openSheet('search');
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
        if (name === 'search') return; // focused already, in the tap
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
        if (this.sheet === 'search') document.activeElement?.blur(); // drop the keyboard
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

    // "We recommend" (D38): owner picks, or tonight's real sellers.
    get recommended() {
        const items = this.allItems();
        return (this.boot.recommended || []).map((key) => items[key]).filter((item) => item && !this.unavailable.has(item.key));
    },

    badgeLabel(badge) { return BADGES[badge] || ''; },

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
        document.querySelector(`#menu-list section[data-slug="${slug}"]`)?.scrollIntoView({ behavior: reducedMotion() ? 'auto' : 'smooth', block: 'start' });
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
    open(item, via = 'menu') {
        this.current = item;
        this.currentVia = via;
        this.picked = {};
        this.note = '';
        this.qty = 1;
        this.openSheet('item');
    },

    // ---- Rows (D37) ------------------------------------------------------
    qtyOf(key) { return this.cart.reduce((n, l) => (l.key === key ? n + l.qty : n), 0); },

    // "+" on a row: straight into the cart, unless the item has options to
    // choose (e.g. Cold / Not cold) — then the sheet opens.
    rowAdd(item, via = 'menu', button = null) {
        try { navigator.vibrate && navigator.vibrate(10); } catch (e) { /* not supported */ }
        button?.classList.add('pop');
        setTimeout(() => button?.classList.remove('pop'), 120);

        if (item.chips && item.chips.length) {
            this.open(item, via);
            return;
        }

        this.addLine({ key: item.key, type: item.type, id: item.id, name: item.name, price: item.price, qty: 1, chips: [], note: '', via });
        this.writeCart();
        this.showPairs(item);
    },

    // "−" takes back the most recent add: one off a plain item, the whole
    // latest line for an item with options.
    rowMinus(item) {
        const line = [...this.cart].reverse().find((l) => l.key === item.key);
        if (!line) return;
        if (line.qty > 1 && !(item.chips && item.chips.length)) {
            line.qty--;
        } else {
            this.cart = this.cart.filter((l) => l.uid !== line.uid);
        }
        this.writeCart();
    },

    // ---- Goes well with (D38) --------------------------------------------
    pairItems(item) {
        const items = this.allItems();
        return (item.pairs || []).map((key) => items[key]).filter((p) => p && !this.unavailable.has(p.key)).slice(0, 3);
    },

    // Under the row just added to; gone after 12 s or the next add.
    showPairs(item) {
        clearTimeout(this._pairTimer);
        this.pairFor = this.pairItems(item).length ? item.key : null;
        if (this.pairFor) this._pairTimer = setTimeout(() => { this.pairFor = null; }, 12000);
    },

    pairAdd(item) {
        this.pairFor = null;
        if (item.chips && item.chips.length) {
            this.open(item, 'pairing');
            return;
        }
        this.addLine({ key: item.key, type: item.type, id: item.id, name: item.name, price: item.price, qty: 1, chips: [], note: '', via: 'pairing' });
        this.writeCart();
        this.flash(`${item.name} added`);
    },

    // ---- Quick add-ons in the cart (D38) -----------------------------------
    get addonItems() {
        const items = this.allItems();
        const inCart = new Set(this.cart.map((l) => l.key));
        return (this.boot.quick_addons || []).map((key) => items[key])
            .filter((item) => item && !this.unavailable.has(item.key) && !inCart.has(item.key))
            .slice(0, 3);
    },

    addonAdd(item) {
        if (item.chips && item.chips.length) {
            this.open(item, 'addon');
            return;
        }
        this.addLine({ key: item.key, type: item.type, id: item.id, name: item.name, price: item.price, qty: 1, chips: [], note: '', via: 'addon' });
        this.writeCart();
    },

    // ---- Order again (D38) — today's prices, from the server -----------------
    againKey() { return 'selum_again_' + (this.boot.token || 'menu'); },
    get againVisible() { return !!this.boot.last_visit && !this.againDismissed && !this.cart.length; },

    dismissAgain() {
        this.againDismissed = true;
        store.set(this.againKey(), this.boot.last_visit?.summary || '');
    },

    orderAgain() {
        const lines = (this.boot.last_visit?.lines || []).filter((l) => !this.unavailable.has(l.key));
        if (!lines.length) {
            this.flash('Those items aren\'t available right now.', true);
            this.dismissAgain();
            return;
        }
        lines.forEach((l) => this.addLine({ ...l, via: 'again' }));
        this.writeCart();
        this.dismissAgain();
        this.openSheet('cart');
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
        this.addLine({ key: item.key, type: item.type, id: item.id, name: item.name, price: item.price, qty: this.qty, chips: Object.values(this.picked).flat(), note: this.note.trim().slice(0, 100), via: this.currentVia });
        this.writeCart();
        this.closeSheet();
        this.flash(`${item.name} added`);
        this.showPairs(item);
    },

    addLine(line) {
        const same = this.cart.find((l) => l.key === line.key && l.note === line.note && l.chips.join('|') === line.chips.join('|'));
        if (same) {
            same.qty = Math.min(20, same.qty + line.qty);
        } else {
            this.cart.push({ ...line, via: line.via || 'menu', uid: Date.now() + Math.random() });
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
                body: JSON.stringify({ channel, lines: this.cart.map((l) => ({ type: l.type, id: l.id, qty: l.qty, chips: l.chips, note: l.note || null, added_via: l.via || 'menu' })) }),
            });
            const data = await res.json().catch(() => ({}));

            if (res.ok && data.ok) {
                this.cart = [];
                this.writeCart();
                this.sentRequest = data.request;
                this.whatsappUrl = data.whatsapp_url || null;
                this.whatsappSlow = false;
                this.latestStatus = 'waiting';
                this.acceptedBy = null;
                this.finalSince = null;
                if (!this.whatsappUrl) {
                    // D36: the tick moment, then the dot flies to Bill.
                    this.closeSheet();
                    this.orderSentMoment();
                } else {
                    this.openSheet('sent');
                }
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

    // ---- Order sent moment (D36) ----------------------------------------------
    // Ring 400 ms, tick 250 ms, hold 1.2 s; then a dot flies to the Bill icon.
    orderSentMoment() {
        this.sentMoment = true;
        const still = reducedMotion();
        setTimeout(() => {
            this.sentMoment = false;
            if (!still) this.flyToBill();
        }, still ? 1500 : 1850);
    },

    flyToBill() {
        const dot = this.$refs.flyDot;
        const bill = this.$refs.billNav;
        if (!dot || !bill) return;
        const to = bill.getBoundingClientRect();
        const dx = to.left + to.width / 2 - window.innerWidth / 2;
        const dy = to.top + 8 - window.innerHeight / 2;
        dot.style.setProperty('--dx', dx + 'px');
        dot.style.setProperty('--dy', dy + 'px');
        dot.classList.remove('go');
        void dot.offsetWidth; // restart the animation
        dot.classList.add('go');
        setTimeout(() => {
            dot.classList.remove('go');
            bill.classList.add('bounce');
            setTimeout(() => bill.classList.remove('bounce'), 400);
        }, 520);
    },

    // ---- Status strip + Bill dot (D36) -----------------------------------------
    applyVisit(data) {
        if (!('latest_status' in data)) return;
        const status = data.latest_status;
        if (FINAL.includes(status)) {
            if (!FINAL.includes(this.latestStatus) || !this.finalSince) this.finalSince = Date.now();
        } else {
            this.finalSince = null;
        }
        this.latestStatus = status;
        this.acceptedBy = data.accepted_by_first_name || null;
        this.lastRound = data.last_round || null;
    },

    get statusText() {
        if (!this.canOrder) return '';
        switch (this.latestStatus) {
            case 'waiting': return this.isRoom ? 'Order sent · waiting for reception' : 'Order sent · waiting for a waiter';
            case 'accepted': return this.acceptedBy ? `${this.acceptedBy} accepted your order ✓` : 'Your order was accepted ✓';
            case 'preparing': return 'Preparing your food';
            case 'ready':
            case 'delivered':
                // Gone 2 minutes after the order is done.
                if (this.finalSince && this.now - this.finalSince > 120000) return '';
                return this.latestStatus === 'delivered' ? 'Your order was delivered ✓' : 'Your order is ready ✓';
            default: return '';
        }
    },

    get statusTone() {
        if (this.latestStatus === 'waiting') return 'wait';
        return FINAL.includes(this.latestStatus) ? 'done' : 'go';
    },

    get billDot() {
        if (this.latestStatus === 'waiting') return 'wait';
        return ['accepted', 'preparing'].includes(this.latestStatus) ? 'go' : '';
    },

    // ---- Another round? (D38) ---------------------------------------------------
    // Once per round, N minutes after the drinks were marked ready, never
    // while a sheet is open, and not once newer drinks were ordered.
    get nudge() {
        const round = this.lastRound;
        const delay = this.boot.round_delay_min || 0;
        if (!this.canOrder || !round || !round.ready_at || round.superseded || delay <= 0 || this.sentMoment) return null;
        if (this.tableState.state === 'closed') return null;
        if ((this.nudgeSeen || store.get('selum_round_seen')) === round.ref) return null;
        if (this.now - Date.parse(round.ready_at) < delay * 60000) return null;
        const summary = round.lines.map((l) => `${l.qty} × ${l.name}`).join(', ');
        return { ...round, summary: `${summary} · ${naira(round.total)}` };
    },

    dismissNudge() {
        const ref = this.lastRound?.ref;
        if (!ref) return;
        this.nudgeSeen = ref;
        store.set('selum_round_seen', ref);
    },

    acceptNudge() {
        this.dismissNudge();
        this.anotherRound('round');
    },

    // ---- Specials countdown (D38) — only a real end time -----------------------
    get specialsMinutesLeft() {
        const ends = this.boot.specials?.ends_at;
        if (!ends) return null;
        return Math.ceil((Date.parse(ends) - this.now) / 60000);
    },

    get specialsOver() { const left = this.specialsMinutesLeft; return left !== null && left <= 0; },

    get specialsLeft() {
        const left = this.specialsMinutesLeft;
        if (left === null || left <= 0 || left > 180) return '';
        if (left < 60) return `Ends in ${left} min`;
        const h = Math.floor(left / 60);
        const m = left % 60;
        return m ? `Ends in ${h} h ${m} min` : `Ends in ${h} h`;
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
            this.closedDismissed = store.get('selum_closed_seen_' + this.boot.token) === state.closed_ref;
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
                this.applyVisit(data);
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
        // D36: while an order is on its way, follow it every 10 s.
        if (['waiting', 'accepted', 'preparing'].includes(this.latestStatus) && this.tableState.state !== 'closed') {
            this.billTimer = setTimeout(() => this.refreshBill(), 10000);
            return;
        }
        if (this.tableState.state === 'room') {
            const fast = this.bill?.live && this.billOpen;
            this.billTimer = setTimeout(() => this.refreshBill(), fast ? 8000 : 30000);
            return;
        }
        if (this.tableState.state !== 'open' || !this.bill) return;
        const fast = this.bill.live && this.billOpen;
        this.billTimer = setTimeout(() => this.refreshBill(), fast ? 8000 : 30000);
    },

    async anotherRound(via = 'round') {
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
            data.lines.forEach((l) => this.addLine({ ...l, via }));
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

    seeMenuAfterClose() {
        this.closedDismissed = true;
        store.set('selum_closed_seen_' + this.boot.token, this.tableState.closed_ref || '');
    },

    flash(message, error = false) {
        this.toast = { message, error };
        clearTimeout(this._toastTimer);
        this._toastTimer = setTimeout(() => { this.toast = null; }, error ? 4000 : 2200);
    },
}));

Alpine.start();
