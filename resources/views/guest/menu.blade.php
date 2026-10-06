{{-- Guest QR menu (Phase 2–5; Phase 7A brand refresh — D30 theme, D31
     thumb-first layout, D32 splash). One HTML response with the whole menu
     embedded as JSON; Alpine (resources/js/guest.js) does the rest.
     Stateless — no session, no CSRF token, no Livewire, no Filament bundle.
     The venue name is $venue (Company::displayName()) — never typed here. --}}
@php
    $canOrder = $boot['mode'] === 'order';
    $isRoom = ($boot['kind'] ?? null) === 'room';
    $initial = mb_strtoupper(mb_substr(trim($venue), 0, 1)) ?: '•';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#FAF7F2">
    {{-- D39: light by default; the guest's own choice, applied before first paint. --}}
    <script>
    try {
        if (localStorage.getItem('selum_theme') === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
            document.querySelector('meta[name="theme-color"]').setAttribute('content', '#121214');
        }
    } catch (e) { /* private mode: light */ }
    </script>
    <title>{{ $boot['place'] ? $boot['place'].' · ' : '' }}{{ $venue }}</title>
    @if ($splashLogo)
        <link rel="preload" as="image" href="{{ $splashLogo }}">
    @endif
    @vite(['resources/css/guest.css', 'resources/js/guest.js'])
</head>
<body>
{{-- D32 splash: inline in this response, no extra request. Skipped when
     shown for this code in the last 4 hours; leaves after ≥1.2 s once the
     menu is ready, never later than 2 s; a tap skips it. --}}
<div id="splash" class="splash" data-token="{{ $boot['token'] ?? 'menu' }}" aria-hidden="true">
    <div class="splash-inner">
        <div class="medallion">
            @if ($splashLogo)
                <img src="{{ $splashLogo }}" alt="" width="184" height="184" decoding="async">
            @else
                <span class="letter">{{ $initial }}</span>
            @endif
        </div>
        <p class="welcome">WELCOME TO</p>
        <p class="name">{{ $venue }}</p>
        <span class="rule"></span>
        @if ($boot['place'])
            <span class="pill">{{ $boot['place'] }}</span>
        @endif
    </div>
    <div class="loader"><span></span></div>
</div>
<script>
(function () {
    var s = document.getElementById('splash');
    if (!s) return;
    var key = 'selum_splash_' + s.getAttribute('data-token'), now = Date.now();
    try {
        var last = parseInt(localStorage.getItem(key) || '0', 10);
        if (last && now - last < 4 * 3600 * 1000) { s.parentNode.removeChild(s); return; }
        localStorage.setItem(key, String(now));
    } catch (e) { /* private mode: show it */ }
    document.documentElement.classList.add('splashing');
    var start = Date.now(), ready = false, gone = false;
    var reduced = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
    function leave() {
        if (gone) return;
        gone = true;
        s.classList.add('leaving');
        setTimeout(function () { if (s.parentNode) s.parentNode.removeChild(s); document.documentElement.classList.remove('splashing'); }, reduced ? 200 : 450);
    }
    function check() { if (ready && Date.now() - start >= 1200) leave(); }
    window.addEventListener('guest-ready', function () { ready = true; check(); });
    setTimeout(check, 1200);
    setTimeout(leave, 2000);
    s.addEventListener('click', leave);
})();
</script>

<script type="application/json" id="guest-boot">@json($boot)</script>

<div x-data="guestMenu" x-cloak x-effect="document.body.classList.toggle('has-cart', canOrder && cart.length > 0)">
    {{-- ===================== Sticky top bar (D34) ===================== --}}
    <header class="topbar">
        <div class="wrap">
            <div class="header">
                <span class="medallion">
                    @if ($logo)
                        <img src="{{ $logo }}" alt="" width="24" height="24" decoding="async">
                    @else
                        <span class="letter">{{ $initial }}</span>
                    @endif
                </span>
                <span class="venue">{{ $venue }}</span>
                <button type="button" class="theme-btn" @click="toggleTheme()" :aria-label="dark ? 'Switch to light mode' : 'Switch to dark mode'">
                    <svg class="moon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z"/></svg>
                    <svg class="sun" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
                </button>
                @if ($boot['place'])
                    <span class="place-wrap">
                        <span class="place-pill" :class="{ glow: hints }">{{ $boot['place'] }}</span>
                        {{-- D35: first visit only --}}
                        <span class="hint-tip" x-show="hintTip" x-transition.opacity role="status">You're ordering for {{ $boot['place'] }}</span>
                    </span>
                @endif
            </div>

            <button type="button" class="search-bar" @click="openSearch()" aria-label="Search the menu">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <span class="search-ph" x-text="placeholder">Search the menu</span>
            </button>

            {{-- D36: the latest order's progress --}}
            <button type="button" class="status-strip" x-show="statusText" x-transition.opacity @click="openSheet('bill')">
                <span class="sdot" :class="statusTone"></span>
                <span class="stext" x-text="statusText"></span>
                <span class="slink">View bill</span>
            </button>
        </div>
    </header>

    <div class="wrap">
        {{-- Moved table (Phase 4) --}}
        <template x-if="tableState.state === 'moved'">
            <p class="banner" role="status">Your table moved to <strong x-text="tableState.moved_to"></strong> — scan the QR on your new table.</p>
        </template>

        @if ($boot['notice'])
            <p class="notice">{{ $boot['notice'] }}</p>
        @endif

        @if ($boot['specials'])
            <div class="specials" x-show="!specialsOver">
                @if ($boot['specials']['image_url'])
                    <img src="{{ $boot['specials']['image_url'] }}" alt="" width="96" height="96" loading="lazy" decoding="async">
                @endif
                <div>
                    @if ($boot['specials']['text'])
                        <p>{{ $boot['specials']['text'] }}</p>
                    @endif
                    {{-- D38: a countdown only to a real end time --}}
                    <span class="countdown" x-show="specialsLeft" x-text="specialsLeft"></span>
                </div>
            </div>
        @endif

        {{-- D38: Order again (returning phone) --}}
        <template x-if="canOrder && againVisible">
            <div class="again card" role="region" aria-label="Order again">
                <button type="button" class="again-x" aria-label="Dismiss" @click="dismissAgain()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
                <p class="again-k">Welcome back · last time you had</p>
                <p class="again-v" x-text="boot.last_visit.summary"></p>
                <button type="button" class="primary again-btn" @click="orderAgain()">Order again</button>
            </div>
        </template>

        {{-- D38: We recommend (owner picks; falls back to tonight's real sellers) --}}
        <template x-if="recommended.length">
            <section aria-label="We recommend">
                <p class="eyebrow">We recommend</p>
                <div class="popular">
                    <template x-for="item in recommended" :key="'rec-' + item.key">
                        <div class="rec-card">
                            <button type="button" class="rec-media" @click="open(item, 'recommended')">
                                <template x-if="item.thumb">
                                    <img class="tile" :src="item.thumb" :alt="item.name" width="150" height="112" loading="lazy" decoding="async" @load="$el.classList.add('loaded')" x-on:error="$el.classList.add('loaded')">
                                </template>
                                <template x-if="!item.thumb">
                                    <div class="tile ph" x-text="initial(item.name)"></div>
                                </template>
                                <template x-if="item.badge">
                                    <span class="badge-pill" :class="'b-' + item.badge" x-text="badgeLabel(item.badge)"></span>
                                </template>
                            </button>
                            <div class="rec-row">
                                <div class="rec-text">
                                    <div class="name" x-text="item.name"></div>
                                    <div class="price" x-text="naira(item.price)"></div>
                                </div>
                                @if ($canOrder)
                                    <button type="button" class="mini-add" :aria-label="'Add ' + item.name" @click="rowAdd(item, 'recommended', $el)"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg></button>
                                @endif
                            </div>
                        </div>
                    </template>
                </div>
            </section>
        </template>

        <main id="menu-list">
            <template x-for="section in sections" :key="'sec-' + section.slug">
                <section :data-slug="section.slug">
                    <div class="section-head" :id="section.slug">
                        <h2 x-text="section.name"></h2>
                        <span x-text="section.items.length + (section.items.length === 1 ? ' item' : ' items')"></span>
                    </div>
                    <template x-for="item in section.items" :key="item.key">
                        <div>
                            {{-- D37: a thumb only when there is a photo; slim row otherwise --}}
                            <div class="row" :class="{ slim: !item.thumb }">
                                <template x-if="item.thumb">
                                    <button type="button" class="row-thumb" @click="open(item)" :aria-label="item.name">
                                        <img class="tile" :src="item.thumb" alt="" width="64" height="64" loading="lazy" decoding="async" @load="$el.classList.add('loaded')" x-on:error="$el.classList.add('loaded')">
                                    </button>
                                </template>
                                <button type="button" class="row-body" @click="open(item)">
                                    <span class="name"><span x-text="item.name"></span>
                                        <template x-if="item.badge"><span class="badge-pill" :class="'b-' + item.badge" x-text="badgeLabel(item.badge)"></span></template>
                                    </span>
                                    <span class="desc" x-show="item.description" x-text="item.description"></span>
                                    <span class="price" x-text="naira(item.price)"></span>
                                </button>
                                @if ($canOrder)
                                    <template x-if="!qtyOf(item.key)">
                                        <button type="button" class="row-add" :aria-label="'Add ' + item.name" @click="rowAdd(item, 'menu', $el)"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg></button>
                                    </template>
                                    <template x-if="qtyOf(item.key)">
                                        <div class="row-stepper" role="group" :aria-label="item.name + ' quantity'">
                                            <button type="button" :aria-label="'One less ' + item.name" @click="rowMinus(item)"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M5 12h14"/></svg></button>
                                            <span x-text="qtyOf(item.key)"></span>
                                            <button type="button" :aria-label="'One more ' + item.name" @click="rowAdd(item, 'menu', $el)"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg></button>
                                        </div>
                                    </template>
                                @endif
                            </div>

                            {{-- D38: Goes well with — under the row just added to --}}
                            <template x-if="pairFor === item.key && pairItems(item).length">
                                <div class="pair-strip" x-transition.opacity>
                                    <p class="pair-k">Goes well with</p>
                                    <div class="pair-chips">
                                        <template x-for="pair in pairItems(item)" :key="'pair-' + item.key + pair.key">
                                            <button type="button" class="pair-chip" @click="pairAdd(pair)">
                                                <span x-text="pair.name + ' · ' + naira(pair.price)"></span>
                                                <span class="pair-plus" aria-hidden="true">+</span>
                                            </button>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>
                </section>
            </template>
            <p class="empty" x-show="!sections.length">Nothing here right now.</p>
        </main>
    </div>

    {{-- ===================== Bottom stack ===================== --}}
    <div class="bottom">
        <div class="bottom-inner">
            {{-- D38: Another round? — once per round, never over a sheet --}}
            <template x-if="nudge && !sheet">
                <div class="nudge" role="dialog" aria-label="Another round" x-transition:enter-start="nudge-enter">
                    <div class="nudge-top">
                        <span class="nudge-icon" aria-hidden="true">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3h10l-1 8a4 4 0 0 1-8 0L7 3ZM12 15v6M8 21h8"/></svg>
                        </span>
                        <div class="nudge-text">
                            <p class="nudge-title">Ready for another round?</p>
                            <p class="nudge-sub" x-text="nudge.summary"></p>
                        </div>
                        <button type="button" class="nudge-x" aria-label="Close" @click="dismissNudge()">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                        </button>
                    </div>
                    <div class="nudge-actions">
                        <button type="button" class="secondary" @click="dismissNudge()">Not now</button>
                        <button type="button" class="primary" @click="acceptNudge()">Add to my order</button>
                    </div>
                </div>
            </template>

            @if ($canOrder)
                <button type="button" class="cart-bar" x-show="cart.length && !sheet" x-transition:enter-start="cart-enter" x-transition:leave-end="cart-enter" @click="openSheet('cart')">
                    <span class="count" x-text="cartCount"></span>
                    <span class="label">View order</span>
                    <span class="total" x-text="naira(cartTotal)"></span>
                </button>
            @endif

            {{-- Pills only when the tab has more than one category --}}
            <nav class="cat-pills" aria-label="Categories" x-show="sections.length > 1">
                <template x-for="section in sections" :key="'pill-' + section.slug">
                    <button type="button" class="cat-pill" :class="{ on: activeSection === section.slug }" @click="scrollTo(section.slug)" x-text="section.name"></button>
                </template>
            </nav>

            {{-- D34: Drinks · Food · Bill · Waiter (search lives in the top bar) --}}
            <nav class="nav" aria-label="Main">
                <button type="button" :class="{ on: tab === 'drinks' && !sheet }" @click="showTab('drinks')">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 3h10l-1 8a4 4 0 0 1-8 0L7 3ZM12 15v6M8 21h8"/></svg>
                    Drinks
                </button>
                <button type="button" :class="{ on: tab === 'food' && !sheet }" @click="showTab('food')">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 3v8M5 3v5a2 2 0 0 0 4 0V3M7 11v10M17 21V3c-2 1.5-3 4-3 7h3"/></svg>
                    Food
                </button>
                @if ($canOrder)
                    <button type="button" x-ref="billNav" :class="{ on: ['bill', 'pay', 'split'].includes(sheet) }" @click="openSheet('bill')">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6"/></svg>
                        Bill
                        <span class="bdot" x-show="billDot" :class="billDot"></span>
                    </button>
                    <button type="button" :class="{ on: sheet === 'call' }" @click="openSheet('call')">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10 21h4"/></svg>
                        {{ $isRoom ? 'Reception' : 'Waiter' }}
                    </button>
                @endif
            </nav>
        </div>
    </div>

    <div class="scrim" x-show="sheet" x-transition.opacity @click="closeSheet()"></div>

    {{-- ===================== Item ===================== --}}
    <div class="sheet" data-sheet="item" role="dialog" aria-modal="true" aria-label="Item" tabindex="-1"
        x-show="sheet === 'item' && current" x-transition:enter-start="sheet-enter" x-transition:leave-end="sheet-enter">
        <div class="grab-zone" @touchstart="dragStart($event)" @touchmove="dragMove($event)" @touchend="dragEnd()"><div class="grab"></div></div>
        <div class="scroll">
            <div class="sheet-photo">
                <template x-if="current && current.large">
                    <img :src="current.large" :alt="current.name" width="640" height="210" decoding="async">
                </template>
                <template x-if="current && !current.large">
                    <div class="ph" x-text="initial(current.name)"></div>
                </template>
                <button type="button" class="close-btn" aria-label="Close" @click="closeSheet()">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>
            <div class="title-row">
                <h2 x-text="current?.name"></h2>
                <span class="price" x-text="current ? naira(current.price) : ''"></span>
            </div>
            <p class="desc-text" x-show="current?.description" x-text="current?.description"></p>

            <template x-if="canOrder && current">
                <div>
                    <template x-for="group in current.chips" :key="group.group">
                        <div>
                            <div class="group-label" x-text="group.group + (group.selection === 'single' ? ' · pick one' : '')"></div>
                            <div class="chips">
                                <template x-for="label in group.options" :key="label">
                                    <button type="button" class="chip" :class="{ on: isPicked(group, label) }" :aria-pressed="isPicked(group, label)" @click="pick(group, label)" x-text="label"></button>
                                </template>
                            </div>
                        </div>
                    </template>
                    <template x-if="current.note">
                        <div>
                            <div class="group-label">Note for the kitchen</div>
                            <textarea class="note" x-model="note" maxlength="100" placeholder="e.g. no crayfish, please" aria-label="Note for the kitchen"></textarea>
                        </div>
                    </template>
                </div>
            </template>
            <template x-if="!canOrder">
                <p class="notice" style="margin: 16px 0 0">{{ $boot['notice'] }}</p>
            </template>
        </div>
        <template x-if="canOrder && current">
            <div class="actions">
                <div class="stepper">
                    <button type="button" @click="qty = Math.max(1, qty - 1)" aria-label="Fewer">−</button>
                    <span x-text="qty"></span>
                    <button type="button" @click="qty = Math.min(20, qty + 1)" aria-label="More">+</button>
                </div>
                <button type="button" class="primary" @click="addToCart()">Add <span x-text="qty"></span> · <span x-text="naira(current.price * qty)"></span></button>
            </div>
        </template>
    </div>

    {{-- ===================== Search =====================
         Full-screen, input pinned at the TOP, so the phone keyboard can never
         cover it (it overlays fixed bottom sheets since Chrome 108, and on
         iOS). Kept in the page (hidden with visibility, not display:none) so
         the Search tap can focus the input synchronously — iOS only opens
         the keyboard for a focus inside the user's own tap. --}}
    <div class="search-panel" data-sheet="search" role="dialog" aria-modal="true" aria-label="Search the menu"
        :class="{ open: sheet === 'search' }" :aria-hidden="sheet !== 'search'" x-ref="searchPanel">
        <div class="search-top">
            <button type="button" class="search-back" aria-label="Close search" @click="closeSheet()">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
            </button>
            <label class="search-box">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" class="search-input" x-ref="searchInput" x-model="search" placeholder="Search the menu" autocomplete="off" enterkeyhint="search" aria-label="Search the menu">
                <button type="button" class="search-clear" x-show="search.length" aria-label="Clear search" @click="search = ''; $refs.searchInput.focus({ preventScroll: true })">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </label>
        </div>
        <div class="search-results">
            <template x-if="search.trim().length < 2 && popular.length">
                <p class="eyebrow" style="margin-left:0">Popular tonight</p>
            </template>
            <template x-for="item in (search.trim().length >= 2 ? searchResults : popular)" :key="'s-' + item.key">
                <div class="item">
                    <button type="button" class="body" style="text-align:left" @click="open(item, 'search')">
                        <div class="name" x-text="item.name"></div>
                        <div class="desc" x-show="item.description" x-text="item.description"></div>
                        <div class="price" x-text="naira(item.price)"></div>
                    </button>
                    <div class="media">
                        <template x-if="item.thumb">
                            <img class="tile loaded" :src="item.thumb" :alt="item.name" width="92" height="92" loading="lazy" decoding="async">
                        </template>
                        <template x-if="!item.thumb">
                            <div class="tile ph" x-text="initial(item.name)"></div>
                        </template>
                        @if ($canOrder)
                            <button type="button" class="add" :aria-label="'Add ' + item.name" @click="rowAdd(item, 'search', $el)">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                            </button>
                        @endif
                    </div>
                </div>
            </template>
            <p class="muted center" x-show="search.trim().length >= 2 && !searchResults.length">No matches</p>
            <p class="muted center small" x-show="search.trim().length < 2 && !popular.length">Type at least two letters.</p>
        </div>
    </div>

    {{-- ===================== Cart ===================== --}}
    <div class="sheet" data-sheet="cart" role="dialog" aria-modal="true" aria-label="Your order" tabindex="-1"
        x-show="sheet === 'cart'" x-transition:enter-start="sheet-enter" x-transition:leave-end="sheet-enter">
        <div class="grab-zone" @touchstart="dragStart($event)" @touchmove="dragMove($event)" @touchend="dragEnd()"><div class="grab"></div></div>
        <div class="scroll">
            <h2>Your order</h2>
            <template x-for="line in cart" :key="line.uid">
                <div class="line">
                    <div class="body">
                        <div class="name" x-text="line.name"></div>
                        <div class="extra" x-show="line.chips.length" x-text="line.chips.join(' · ')"></div>
                        <div class="muted small" x-show="line.note" x-text="'“' + line.note + '”'"></div>
                        <div class="amount" x-text="naira(line.price * line.qty)"></div>
                        <button type="button" class="link-action" @click="remove(line)">Remove</button>
                    </div>
                    <div class="stepper small">
                        <button type="button" @click="step(line, -1)" aria-label="Fewer">−</button>
                        <span x-text="line.qty"></span>
                        <button type="button" @click="step(line, 1)" aria-label="More">+</button>
                    </div>
                </div>
            </template>
            {{-- D38: Quick add-ons (owner-picked; not already in the cart, not sold out) --}}
            <template x-if="addonItems.length">
                <div class="addons">
                    <p class="addons-k">Anything else?</p>
                    <p class="muted small">Quick add-ons</p>
                    <div class="addon-row">
                        <template x-for="item in addonItems" :key="'add-' + item.key">
                            <div class="addon-card">
                                <div class="name" x-text="item.name"></div>
                                <div class="price" x-text="naira(item.price)"></div>
                                <button type="button" class="mini-add" :aria-label="'Add ' + item.name" @click="addonAdd(item)"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg></button>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
            <div class="total"><span>Total</span><span x-text="naira(cartTotal)"></span></div>
            <p class="muted small center">{{ $isRoom ? 'Reception confirms your order before anything is made. It goes on your room bill.' : 'A waiter confirms your order before anything is made.' }}</p>
        </div>
        @if ($isRoom)
            <div class="actions stacked">
                <template x-if="boot.whatsapp">
                    <button type="button" class="whatsapp" :disabled="sending || !cart.length" @click="send('whatsapp')">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2c-1.5 0-3-.4-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.7.8-.8 1-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.3-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6.5-.1 1.5-.6 1.7-1.2s.2-1.1.2-1.2-.2-.2-.4-.3Z"/></svg>
                        <span x-text="sending ? 'Sending…' : 'Send order on WhatsApp'"></span>
                    </button>
                </template>
                <button type="button" :class="boot.whatsapp ? 'text-button' : 'primary'" :disabled="sending || !cart.length" @click="send('none')"
                    x-text="boot.whatsapp ? 'Order without WhatsApp' : (sending ? 'Sending…' : 'Send to reception')"></button>
            </div>
        @else
            <div class="actions">
                <button type="button" class="primary" :disabled="sending || !cart.length" @click="send()" x-text="sending ? 'Sending…' : 'Send to waiter · ' + naira(cartTotal)"></button>
            </div>
        @endif
    </div>

    {{-- ===================== Sent ===================== --}}
    <div class="sheet" data-sheet="sent" role="dialog" aria-modal="true" aria-label="Order sent" tabindex="-1"
        x-show="sheet === 'sent' && sentRequest" x-transition:enter-start="sheet-enter" x-transition:leave-end="sheet-enter">
        <div class="grab-zone" @touchstart="dragStart($event)" @touchmove="dragMove($event)" @touchend="dragEnd()"><div class="grab"></div></div>
        <div class="scroll">
            <div class="sent">
                <div class="tick" aria-hidden="true">✓</div>
                <h2 x-text="isRoom ? (sentRequest?.pending ? 'Order sent ✓' : sentRequest?.status_label) : (sentRequest?.pending ? 'Sent — waiting for a waiter to confirm' : sentRequest?.status_label)"></h2>
                <p class="muted">Ref <strong x-text="sentRequest?.ref"></strong><span x-show="isRoom && sentRequest?.pending"> — reception will confirm shortly.</span></p>
                <template x-if="whatsappUrl && whatsappSlow">
                    <div class="wa-fallback">
                        <a class="whatsapp" :href="whatsappUrl">Didn't open? Tap here</a>
                        <p class="muted small">Reception can see your order anyway.</p>
                    </div>
                </template>
            </div>
            <template x-for="(line, i) in (sentRequest?.lines || [])" :key="i">
                <div class="line">
                    <div class="body"><span class="name" x-text="line.qty + '× ' + line.name"></span>
                        <div class="extra" x-show="line.chips.length" x-text="line.chips.join(' · ')"></div></div>
                    <span class="amount" x-text="naira(line.price * line.qty)"></span>
                </div>
            </template>
            <div class="total"><span>Total</span><span x-text="naira(sentRequest?.total || 0)"></span></div>
        </div>
        <div class="actions stacked">
            <button type="button" class="primary" @click="openSheet('bill')">See my bill</button>
            <button type="button" class="text-button" x-show="sentRequest?.pending" @click="cancel(sentRequest)">Cancel request</button>
        </div>
    </div>

    {{-- ===================== Bill ===================== --}}
    <div class="sheet" data-sheet="bill" role="dialog" aria-modal="true" aria-label="Your bill" tabindex="-1"
        x-show="sheet === 'bill'" x-transition:enter-start="sheet-enter" x-transition:leave-end="sheet-enter">
        <div class="grab-zone" @touchstart="dragStart($event)" @touchmove="dragMove($event)" @touchend="dragEnd()"><div class="grab"></div></div>
        <div class="scroll">
            <h2>Your bill</h2>
            <template x-if="!bill">
                <p class="muted" x-text="billMessage || (tableState.state === 'closed' ? 'This table is closed.' : 'No open bill yet — it starts with your first order.')"></p>
            </template>

            <template x-if="bill">
                <div>
                    <div class="card totals">
                        <div class="muted small">Remaining</div>
                        <div class="remaining" x-text="naira(bill.totals.remaining)"></div>
                        <div class="muted small">
                            Bill <span x-text="naira(bill.totals.bill)"></span> ·
                            Paid <span x-text="naira(bill.totals.paid)"></span> ·
                            Claimed <span x-text="naira(bill.totals.claimed)"></span>
                        </div>
                    </div>

                    <template x-if="bill.tracker">
                        <div class="card tracker">
                            <div class="small muted">Latest order <strong x-text="bill.tracker.ref"></strong></div>
                            <div class="steps">
                                <template x-for="(label, n) in bill.tracker.steps" :key="n">
                                    <div class="step">
                                        <div class="dots">
                                            <span class="tdot food" x-show="bill.tracker.food !== null" :class="{ on: bill.tracker.food !== null && bill.tracker.food >= n }"></span>
                                            <span class="tdot drink" x-show="bill.tracker.drinks !== null" :class="{ on: bill.tracker.drinks !== null && bill.tracker.drinks >= n }"></span>
                                        </div>
                                        <span class="step-label" x-text="label"></span>
                                    </div>
                                </template>
                            </div>
                            <div class="legend">
                                <span x-show="bill.tracker.food !== null"><span class="tdot food on"></span> Food</span>
                                <span x-show="bill.tracker.drinks !== null"><span class="tdot drink on"></span> Drinks</span>
                            </div>
                        </div>
                    </template>

                    <div class="bill-actions" :class="{ single: isRoom }">
                        <button type="button" class="outline" @click="anotherRound()" :disabled="loadingRound" x-text="loadingRound ? 'Loading…' : 'Another round'"></button>
                        <button type="button" class="secondary" x-show="!isRoom" @click="openSplit()">Split the bill</button>
                    </div>

                    <h3 class="bill-title">On your bill</h3>
                    <p class="muted small" x-show="!bill.sections.on_bill.length">Nothing on the bill yet.</p>
                    <template x-for="(l, k) in bill.sections.on_bill" :key="'b' + k">
                        <div class="line">
                            <div class="body">
                                <div class="name" x-text="l.qty + '× ' + l.name"></div>
                                <div class="extra" x-show="l.chips.length" x-text="l.chips.join(' · ')"></div>
                                <span class="status" :class="statusClass(l)" x-text="l.status_label"></span>
                            </div>
                            <span class="amount" x-text="naira(l.total)"></span>
                        </div>
                    </template>

                    <template x-if="bill.sections.waiting.length">
                        <div>
                            <h3 class="bill-title">Waiting</h3>
                            <template x-for="(l, k) in bill.sections.waiting" :key="'w' + k">
                                <div class="line waiting">
                                    <div class="body">
                                        <div class="name" x-text="l.qty + '× ' + l.name"></div>
                                        <span class="status" x-text="l.status_label"></span>
                                    </div>
                                    <span class="amount" x-text="naira(l.total)"></span>
                                </div>
                            </template>
                            <template x-for="r in requests.filter((r) => r.pending)" :key="'c' + r.ref">
                                <button type="button" class="link-action" @click="cancel(r)" x-text="'Cancel order ' + r.ref"></button>
                            </template>
                        </div>
                    </template>

                    <template x-if="bill.sections.unavailable.length">
                        <div>
                            <h3 class="bill-title">Unavailable</h3>
                            <template x-for="(l, k) in bill.sections.unavailable" :key="'u' + k">
                                <div class="line struck">
                                    <div class="body">
                                        <div class="name" x-text="l.qty + '× ' + l.name"></div>
                                        <div class="small muted" x-text="l.reason"></div>
                                    </div>
                                    <span class="amount" x-text="naira(l.total)"></span>
                                </div>
                            </template>
                        </div>
                    </template>

                    <template x-if="bill.sections.paid.length">
                        <div>
                            <button type="button" class="bill-title" @click="paidOpen = !paidOpen" :aria-expanded="paidOpen">
                                <span>Paid <span class="muted small" x-text="'(' + bill.sections.paid.length + ')'"></span></span>
                                <span class="muted small" x-text="paidOpen ? 'Hide' : 'Show'"></span>
                            </button>
                            <template x-for="(l, k) in (paidOpen ? bill.sections.paid : [])" :key="'p' + k">
                                <div class="line">
                                    <div class="body"><span class="name muted" x-text="l.qty + '× ' + l.name"></span></div>
                                    <span class="amount muted" x-text="naira(l.total)"></span>
                                </div>
                            </template>
                        </div>
                    </template>

                    <template x-if="bill.claims.length">
                        <div>
                            <h3 class="bill-title">Your payments</h3>
                            <template x-for="c in bill.claims" :key="'cl' + c.id">
                                <div class="line">
                                    <div class="body">
                                        <div class="name" x-text="c.payer_name + ' · ' + naira(c.amount)"></div>
                                        <span class="status" :class="c.status === 'open' ? 'active' : 'done'" x-text="c.status_label"></span>
                                    </div>
                                    <button type="button" class="link-action" x-show="c.status === 'open'" @click="withdraw(c)">Withdraw</button>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </template>
        </div>
        <template x-if="bill">
            <div class="actions">
                <button type="button" class="primary" @click="openPay(bill.totals.remaining)" :disabled="!bill.totals.bill">Pay by transfer</button>
            </div>
        </template>
    </div>

    {{-- ===================== Split (records nothing on the server) ===================== --}}
    <div class="sheet" data-sheet="split" role="dialog" aria-modal="true" aria-label="Split the bill" tabindex="-1"
        x-show="sheet === 'split'" x-transition:enter-start="sheet-enter" x-transition:leave-end="sheet-enter">
        <div class="grab-zone" @touchstart="dragStart($event)" @touchmove="dragMove($event)" @touchend="dragEnd()"><div class="grab"></div></div>
        <div class="scroll" x-show="bill">
            <h2>Split the bill</h2>
            <div class="seg" role="tablist">
                <button type="button" role="tab" :class="{ on: splitMode === 'equal' }" @click="splitMode = 'equal'">Equal</button>
                <button type="button" role="tab" :class="{ on: splitMode === 'items' }" @click="splitMode = 'items'">By items</button>
            </div>
            <div x-show="splitMode === 'equal'" class="center">
                <div class="muted small">How many people?</div>
                <div class="stepper" style="margin-top:8px">
                    <button type="button" @click="people = Math.max(2, people - 1)" aria-label="Fewer people">−</button>
                    <span x-text="people"></span>
                    <button type="button" @click="people = Math.min(10, people + 1)" aria-label="More people">+</button>
                </div>
                <div class="per-person" x-text="naira(equalShare.each) + ' each'"></div>
                <div class="muted small" x-show="equalShare.extra > 0" x-text="naira(equalShare.extra) + ' extra on one person'"></div>
            </div>
            <div x-show="splitMode === 'items'">
                <template x-for="(l, k) in (bill?.sections.on_bill || [])" :key="'s' + k">
                    <div class="line">
                        <label class="body" style="display:flex; gap:10px; align-items:center; min-height:44px">
                            <input type="checkbox" :checked="(picks[k] || 0) > 0" @change="picks[k] = $event.target.checked ? l.qty : 0" style="width:22px; height:22px; accent-color: var(--red)">
                            <span x-text="l.qty + '× ' + l.name"></span>
                        </label>
                        <div class="stepper small" x-show="l.qty > 1 && (picks[k] || 0) > 0">
                            <button type="button" @click="picks[k] = Math.max(1, (picks[k] || 0) - 1)" aria-label="Fewer">−</button>
                            <span x-text="picks[k] || 0"></span>
                            <button type="button" @click="picks[k] = Math.min(l.qty, (picks[k] || 0) + 1)" aria-label="More">+</button>
                        </div>
                    </div>
                </template>
                <div class="total"><span>Your share</span><span x-text="naira(itemsShare)"></span></div>
            </div>
        </div>
        <div class="actions">
            <button type="button" class="primary" :disabled="splitAmount <= 0" @click="openPay(splitAmount)" x-text="'Pay this amount · ' + naira(splitAmount)"></button>
        </div>
    </div>

    {{-- ===================== Pay by transfer ===================== --}}
    <div class="sheet" data-sheet="pay" role="dialog" aria-modal="true" aria-label="Pay by transfer" tabindex="-1"
        x-show="sheet === 'pay'" x-transition:enter-start="sheet-enter" x-transition:leave-end="sheet-enter">
        <div class="grab-zone" @touchstart="dragStart($event)" @touchmove="dragMove($event)" @touchend="dragEnd()"><div class="grab"></div></div>
        <div class="scroll">
            <h2>Pay by transfer</h2>
            <p class="muted small" x-show="!accounts.length">Ask your waiter for the account details.</p>
            <template x-for="a in accounts" :key="'a' + a.id">
                <div class="account">
                    <div class="small muted" x-text="a.bank"></div>
                    <div class="acct-number" x-text="a.number"></div>
                    <div class="small" x-text="a.name"></div>
                    <button type="button" class="copy" :class="{ done: copied === a.id }" @click="copy(a)" x-text="copied === a.id ? 'Copied ✓' : 'Copy'"></button>
                </div>
            </template>
            <h3 class="bill-title">I've paid</h3>
            <label class="field"><span>Your name (on the account you paid from)</span>
                <input type="text" x-model="claimName" maxlength="60" autocomplete="name"></label>
            <label class="field"><span>Amount</span>
                <input type="number" inputmode="numeric" x-model.number="claimAmount" min="1"></label>
            <label class="field" x-show="accounts.length"><span>Which account? (optional)</span>
                <select x-model="claimAccount">
                    <option value="">Not sure</option>
                    <template x-for="a in accounts" :key="'o' + a.id"><option :value="a.id" x-text="a.bank + ' · ' + a.number"></option></template>
                </select></label>
            <p class="muted small center" style="margin-top:12px">{{ $isRoom ? 'Reception checks the transfer before it comes off your bill.' : 'Your waiter checks the transfer before marking the bill paid.' }}</p>
        </div>
        <div class="actions">
            <button type="button" class="primary" @click="sendClaim()" :disabled="claiming" x-text="claiming ? 'Sending…' : 'I\'ve paid'"></button>
        </div>
    </div>

    {{-- ===================== Call waiter / Call reception ===================== --}}
    <div class="sheet" data-sheet="call" role="dialog" aria-modal="true" aria-label="{{ $isRoom ? 'Call reception' : 'Call waiter' }}" tabindex="-1"
        x-show="sheet === 'call'" x-transition:enter-start="sheet-enter" x-transition:leave-end="sheet-enter">
        <div class="grab-zone" @touchstart="dragStart($event)" @touchmove="dragMove($event)" @touchend="dragEnd()"><div class="grab"></div></div>
        <div class="scroll">
            <h2>{{ $isRoom ? 'Call reception' : 'Call your waiter' }}</h2>
            <div class="call-grid">
                <button type="button" class="call-btn" @click="callWaiter('ice')" :disabled="calling">🧊<span>Ice</span></button>
                <button type="button" class="call-btn" @click="callWaiter('cups')" :disabled="calling">🥤<span>Cups</span></button>
                @if ($isRoom)
                    <button type="button" class="call-btn" @click="callWaiter('cutlery')" :disabled="calling">🍴<span>Cutlery</span></button>
                @else
                    <button type="button" class="call-btn" @click="callWaiter('bill')" :disabled="calling">🧾<span>Bill</span></button>
                @endif
                <button type="button" class="call-btn" @click="otherOpen = !otherOpen" :disabled="calling" :aria-expanded="otherOpen">✋<span>Other</span></button>
            </div>
            <div x-show="otherOpen" class="other">
                <input type="text" x-model="callNote" maxlength="60" placeholder="What do you need?" aria-label="What do you need?">
                <button type="button" class="primary" @click="callWaiter('other')" :disabled="calling || !callNote.trim()">Send</button>
            </div>
            <template x-if="bill && bill.call && bill.call.recent">
                <p class="call-status" x-text="bill.call.status === 'acknowledged' ? (isRoom ? 'Reception is on it 🙌' : 'Waiter is on the way 🚶') : (bill.call.status === 'open' ? (isRoom ? 'Reception has been called' : 'Waiter has been called') : '')"></p>
            </template>
            <template x-if="!(bill && bill.call && bill.call.recent) && callSent">
                <p class="call-status" x-text="isRoom ? 'Reception has been called' : 'Waiter has been called'"></p>
            </template>
        </div>
    </div>

    {{-- D36: Order sent --}}
    <div class="sent-moment" x-show="sentMoment" x-transition.opacity role="status" aria-live="polite">
        <svg class="sent-ring" width="96" height="96" viewBox="0 0 96 96" aria-hidden="true">
            <circle class="sent-circle" cx="48" cy="48" r="44" fill="none" stroke-width="4"/>
            <path class="sent-tick" d="M30 49l12 12 24-26" fill="none" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <p class="sent-title">Order sent</p>
        <p class="muted">{{ $isRoom ? 'Reception will confirm it shortly' : 'A waiter will confirm it shortly' }}</p>
    </div>
    <span class="fly-dot" x-ref="flyDot" aria-hidden="true"></span>

    @if (! $isRoom && $canOrder)
        {{-- Closed table (D11): thanks, review, specials — tables only --}}
        <div class="closed-page" x-show="tableState.state === 'closed' && !closedDismissed" x-transition.opacity role="dialog" aria-modal="true" aria-label="This table is closed">
            <div class="closed-top">
                <span class="medallion big">
                    @if ($splashLogo)
                        <img src="{{ $splashLogo }}" alt="" width="104" height="104" decoding="async">
                    @else
                        <span class="letter">{{ $initial }}</span>
                    @endif
                </span>
                <h2 class="closed-title">Thanks for visiting</h2>
                <p class="muted">{{ $boot['place'] }} is closed. We hope to see you again soon.</p>
            </div>
            <div class="closed-actions">
                <template x-if="boot.review_url">
                    <a class="review-btn" :href="boot.review_url" target="_blank" rel="noopener">
                        <span class="stars" aria-hidden="true">★★★</span> Rate us on Google
                    </a>
                </template>
                <template x-if="boot.specials_whatsapp_url">
                    <div>
                        <a class="wa-outline" :href="boot.specials_whatsapp_url" target="_blank" rel="noopener">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="#25D366" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2c-1.5 0-3-.4-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Z"/></svg>
                            Get our specials on WhatsApp
                        </a>
                        <p class="muted small center">Only if you want. You can stop anytime.</p>
                    </div>
                </template>
                <button type="button" class="text-button" @click="seeMenuAfterClose()">See the menu</button>
            </div>
        </div>
    @endif

    <div class="toast" :class="{ error: toast?.error }" x-show="toast" x-transition.opacity role="status" aria-live="polite">
        <template x-if="toast?.error">
            <svg class="warn-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0ZM12 9v4M12 17h.01"/></svg>
        </template>
        <span x-text="toast?.message"></span>
    </div>
</div>
</body>
</html>
