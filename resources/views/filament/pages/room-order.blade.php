<x-filament-panels::page>
    <div>
    @if(! $roomId)
        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
            <h3 class="font-bold text-gray-900 dark:text-white mb-3">Select a checked-in room</h3>

            @if($checkedInBookings->isEmpty())
                <p class="text-gray-500">No rooms are currently checked in.</p>
            @else
                <div class="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-6 gap-3">
                    @foreach($checkedInBookings as $booking)
                        <button type="button" wire:click="selectRoom({{ $booking->room_id }})"
                            class="p-4 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-primary-500 text-center">
                            <div class="text-lg font-bold text-gray-900 dark:text-white">{{ $booking->room->number }}</div>
                            <div class="text-xs text-gray-500 truncate">{{ $booking->guest->name }}</div>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    @else
        {{-- Cart is Alpine-managed (instant add/remove, no round-trip per
             click) — same optimistic pattern as pos.blade.php's posCart(),
             just without the payment/checkout logic this screen doesn't
             need. The server only ever sees the cart once, on submit. --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 pb-24 lg:pb-0"
            x-data="{
                cart: {},
                cartOpen: false,
                isSubmitting: false,
                get cartLines() { return Object.entries(this.cart).map(([key, line]) => ({ key, ...line })); },
                get cartCount() { return Object.values(this.cart).reduce((sum, i) => sum + i.quantity, 0); },
                get cartTotal() { return Object.values(this.cart).reduce((sum, i) => sum + i.price * i.quantity, 0); },
                addToCart(key, name, price) {
                    if (this.cart[key]) {
                        this.cart[key].quantity++;
                    } else {
                        this.cart[key] = { name, price, quantity: 1 };
                    }
                },
                decrementLine(key) {
                    if (!this.cart[key]) return;
                    this.cart[key].quantity--;
                    if (this.cart[key].quantity <= 0) delete this.cart[key];
                },
                removeLine(key) {
                    delete this.cart[key];
                },
                submitOrder() {
                    if (Object.keys(this.cart).length === 0 || this.isSubmitting) return;
                    this.isSubmitting = true;
                    $wire.call('submitOrder', this.cart).then(() => { this.isSubmitting = false; });
                },
            }"
            @room-order-submitted.window="cart = {}; cartOpen = false;">
            <div class="lg:col-span-2 space-y-4">
                <div class="flex justify-between items-center">
                    <div class="flex items-center gap-3">
                        <button type="button" wire:click="changeRoom" class="text-sm text-primary-600 font-bold">&larr; Change room</button>
                        <span class="text-sm font-bold text-gray-900 dark:text-white">Room {{ $roomNumber }}</span>
                    </div>
                    <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search products..."
                        class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white text-sm" />
                </div>

                @if(! $search)
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="$set('activeCategoryId', null)"
                            class="px-3 py-1 rounded-full text-xs font-bold border {{ ! $activeCategoryId ? 'bg-primary-600 text-white border-primary-600' : 'border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300' }}">
                            All
                        </button>
                        @foreach($categories as $category)
                            <button type="button" wire:click="$set('activeCategoryId', {{ $category->id }})"
                                class="px-3 py-1 rounded-full text-xs font-bold border {{ $activeCategoryId === $category->id ? 'bg-primary-600 text-white border-primary-600' : 'border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300' }}">
                                {{ $category->name }}
                            </button>
                        @endforeach
                    </div>
                @endif

                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                    @foreach($products as $product)
                        <button type="button" @click="addToCart('{{ $product->id }}', '{{ addslashes($product->name) }}', {{ $product->price }})"
                            class="p-3 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-primary-500 text-left bg-white dark:bg-gray-800 touch-manipulation">
                            <div class="font-bold text-sm text-gray-900 dark:text-white">{{ $product->name }}</div>
                            <div class="text-xs text-gray-500">₦{{ number_format($product->price, 2) }}</div>
                        </button>
                    @endforeach
                    @foreach($menuItems as $menuItem)
                        <button type="button" @click="addToCart('menu_{{ $menuItem->id }}', '{{ addslashes($menuItem->name) }}', {{ $menuItem->sale_price }})"
                            class="p-3 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-primary-500 text-left bg-white dark:bg-gray-800 touch-manipulation">
                            <div class="font-bold text-sm text-gray-900 dark:text-white">{{ $menuItem->name }}</div>
                            <div class="text-xs text-gray-500">₦{{ number_format($menuItem->sale_price, 2) }}</div>
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="hidden lg:block bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 space-y-3 h-fit">
                <h3 class="font-bold text-gray-900 dark:text-white">Cart</h3>

                <template x-if="cartLines.length === 0">
                    <p class="text-gray-400 text-sm">No items yet — tap a product to add it.</p>
                </template>
                <template x-for="line in cartLines" :key="line.key">
                    <div class="flex justify-between items-center text-sm border-b border-gray-100 dark:border-gray-700 pb-2">
                        <div>
                            <div class="font-bold text-gray-900 dark:text-white" x-text="line.name"></div>
                            <div class="text-gray-500" x-text="'₦' + line.price.toLocaleString() + ' x ' + line.quantity"></div>
                        </div>
                        <div class="flex items-center gap-1">
                            <button type="button" @click="decrementLine(line.key)" class="w-6 h-6 rounded bg-gray-200 dark:bg-gray-600 font-bold">-</button>
                            <button type="button" @click="removeLine(line.key)" class="text-red-500 text-xs ml-2">Remove</button>
                        </div>
                    </div>
                </template>

                <div class="flex justify-between font-bold text-gray-900 dark:text-white pt-2">
                    <span>Total</span>
                    <span x-text="'₦' + cartTotal.toLocaleString()"></span>
                </div>

                <button type="button" @click="submitOrder" :disabled="cartCount === 0 || isSubmitting"
                    class="w-full px-4 py-3 rounded-lg bg-emerald-600 hover:bg-emerald-700 disabled:bg-gray-400 disabled:cursor-not-allowed text-white font-bold">
                    <span x-show="!isSubmitting">Send to Kitchen/Bar</span>
                    <span x-show="isSubmitting" x-cloak>Sending…</span>
                </button>
            </div>

            {{-- Mobile: cart collapses into a sticky bottom bar, tap to expand
                 into a bottom sheet for line edits — matches every other
                 multi-step flow's sticky-CTA treatment instead of sinking below
                 a potentially long product grid. --}}
            <div class="lg:hidden">
                <x-mobile.sticky-cta-bar>
                    <x-slot:context>
                        <button type="button" @click="cartOpen = true" class="w-full text-center">
                            <span x-text="cartCount"></span> item(s) · ₦<span x-text="cartTotal.toLocaleString()"></span> — tap to review
                        </button>
                    </x-slot:context>
                    <button type="button" @click="submitOrder" :disabled="cartCount === 0 || isSubmitting"
                        class="w-full min-h-[48px] py-4 rounded-xl text-white text-lg font-bold touch-manipulation"
                        :class="cartCount > 0 ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-gray-400 cursor-not-allowed'">
                        <span x-show="!isSubmitting">Send to Kitchen/Bar</span>
                        <span x-show="isSubmitting" x-cloak>Sending…</span>
                    </button>
                </x-mobile.sticky-cta-bar>

                <x-mobile.bottom-sheet show="cartOpen" title="Cart">
                    <template x-if="cartLines.length === 0">
                        <p class="text-gray-400 text-sm py-4 text-center">No items yet — tap a product to add it.</p>
                    </template>
                    <template x-for="line in cartLines" :key="line.key">
                        <div class="flex justify-between items-center text-sm border-b border-gray-100 dark:border-gray-700 py-2">
                            <div>
                                <div class="font-bold text-gray-900 dark:text-white" x-text="line.name"></div>
                                <div class="text-gray-500" x-text="'₦' + line.price.toLocaleString() + ' x ' + line.quantity"></div>
                            </div>
                            <div class="flex items-center gap-2">
                                <button type="button" @click="decrementLine(line.key)" class="min-w-[44px] min-h-[44px] rounded-lg bg-gray-200 dark:bg-gray-600 font-bold touch-manipulation">-</button>
                                <button type="button" @click="removeLine(line.key)" class="min-h-[44px] px-2 text-red-500 text-xs font-bold touch-manipulation">Remove</button>
                            </div>
                        </div>
                    </template>
                    <div class="flex justify-between font-bold text-gray-900 dark:text-white pt-3">
                        <span>Total</span>
                        <span x-text="'₦' + cartTotal.toLocaleString()"></span>
                    </div>
                </x-mobile.bottom-sheet>
            </div>
        </div>
    @endif

    {{-- Room order ticket printing — same window.open() popup pattern as
         the POS bill printer (window.printPOSBill in pos.blade.php), one
         ticket per destination order. Built with DOM APIs (createElement/
         textContent) rather than an innerHTML template string: this file
         is a Filament Page, and inline <script> source containing literal
         "<tag>" text inside a JS template literal trips up how PHP's
         DOMDocument (used by Livewire's dev-mode single-root-element
         check) parses <script> as raw text in some libxml versions —
         avoided entirely by never writing tag characters into the script
         source in the first place. --}}
    <script>
        window.addEventListener('print-room-ticket', (event) => {
            printRoomTicket(event.detail[0] ?? event.detail);
        });

        function printRoomTicket(d) {
            const win = window.open('', '_blank', 'width=380,height=600,scrollbars=yes,resizable=yes');
            if (!win) { alert('Please allow pop-ups to print the ticket.'); return; }

            win.document.title = d.destination + ' Ticket - Room ' + d.roomNumber;

            const style = win.document.createElement('style');
            style.textContent = [
                '* { margin:0; padding:0; box-sizing:border-box; }',
                "body { font-family: 'Courier New', monospace; font-size:14px; width:80mm; padding:10px; color:#000; }",
                'h1 { text-align:center; font-size:18px; letter-spacing:2px; margin-bottom:2px; }',
                '.sub { text-align:center; font-size:13px; margin-bottom:4px; font-weight:bold; }',
                '.dashed { border-top:1px dashed #000; margin:6px 0; }',
                '.meta { font-size:13px; margin-bottom:2px; }',
                'table { width:100%; border-collapse:collapse; }',
                '@media print { body { width:auto; } button { display:none; } }',
            ].join('\n');
            win.document.head.appendChild(style);

            const body = win.document.body;
            const el = (tag, opts = {}) => {
                const node = win.document.createElement(tag);
                if (opts.className) node.className = opts.className;
                if (opts.text !== undefined) node.textContent = opts.text;
                return node;
            };

            body.appendChild(el('h1', { text: 'ROOM ORDER' }));
            body.appendChild(el('div', { className: 'sub', text: (d.destination || '').toUpperCase() + ' TICKET' }));
            body.appendChild(el('div', { className: 'dashed' }));

            const roomLine = el('div', { className: 'meta' });
            roomLine.appendChild(win.document.createTextNode('Room  : '));
            const roomStrong = el('strong', { text: d.roomNumber });
            roomLine.appendChild(roomStrong);
            body.appendChild(roomLine);

            body.appendChild(el('div', { className: 'meta', text: 'Order : ' + d.orderNumber }));
            body.appendChild(el('div', { className: 'meta', text: 'Date  : ' + d.date }));
            body.appendChild(el('div', { className: 'meta', text: 'Staff : ' + d.staff }));
            body.appendChild(el('div', { className: 'dashed' }));

            const table = el('table');
            const tbody = el('tbody');
            (d.items || []).forEach((item) => {
                const tr = el('tr');
                const td = el('td', { text: item.quantity + 'x ' + item.name });
                td.style.padding = '4px 6px';
                td.style.fontSize = '16px';
                tr.appendChild(td);
                tbody.appendChild(tr);
            });
            table.appendChild(tbody);
            body.appendChild(table);
            body.appendChild(el('div', { className: 'dashed' }));

            win.focus();
            setTimeout(() => { win.print(); }, 600);
        }
    </script>
    </div>
</x-filament-panels::page>
