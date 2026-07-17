<x-filament-panels::page>
    @php($d = $this->dashboardData())

    {{-- ── Filter bar ─────────────────────────────────────────────── --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 flex flex-wrap items-end gap-4">
        <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">Date range</label>
            <select wire:model.live="preset" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm">
                <option value="today">Today</option>
                <option value="yesterday">Yesterday</option>
                <option value="this_week">This Week</option>
                <option value="this_month">This Month</option>
                <option value="last_month">Last Month</option>
                <option value="custom">Custom…</option>
            </select>
        </div>

        @if($preset === 'custom')
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">From</label>
                <input type="date" wire:model.live="customFrom" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">To</label>
                <input type="date" wire:model.live="customTo" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
            </div>
        @endif

        <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">Compare to</label>
            <select wire:model.live="comparisonMode" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm">
                <option value="off">Off</option>
                <option value="previous_period">Previous period</option>
                <option value="same_period_last_month">Same period last month</option>
                <option value="custom">Custom range…</option>
            </select>
        </div>

        @if($comparisonMode === 'custom')
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">Compare from</label>
                <input type="date" wire:model.live="comparisonCustomFrom" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">Compare to</label>
                <input type="date" wire:model.live="comparisonCustomTo" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
            </div>
        @endif

        <div class="text-xs text-gray-500 ml-auto">
            {{ $d['range']->start->format('M j, Y') }} – {{ $d['range']->end->format('M j, Y') }}
            @if($d['comparison'])
                vs {{ $d['comparison']->start->format('M j, Y') }} – {{ $d['comparison']->end->format('M j, Y') }}
            @endif
        </div>
    </div>

    {{-- ── Owner headline strip: Profit / Cash / Gap ──────────────── --}}
    @php($owner = $d['owner'])
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
            <div class="text-xs font-semibold text-gray-500 uppercase">Profit Earned</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white mt-1">₦{{ number_format($owner['profit']['value'], 2) }}</div>
            @if($owner['profit']['delta'])
                @php($pct = $owner['profit']['delta']['percent'])
                <div class="text-xs mt-1 {{ $owner['profit']['delta']['absolute'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                    {{ $owner['profit']['delta']['absolute'] >= 0 ? '▲' : '▼' }}
                    ₦{{ number_format(abs($owner['profit']['delta']['absolute']), 2) }}
                    @if($pct !== null) ({{ number_format($pct, 1) }}%) @endif
                </div>
            @endif
            @if($owner['profit']['has_estimated'])
                <div class="text-[10px] text-amber-600 mt-1">Includes estimated margins</div>
            @endif
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
            <div class="text-xs font-semibold text-gray-500 uppercase">Cash in Hand</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white mt-1">₦{{ number_format($owner['cash']['value'], 2) }}</div>
            @if($owner['cash']['delta'])
                @php($pct = $owner['cash']['delta']['percent'])
                <div class="text-xs mt-1 {{ $owner['cash']['delta']['absolute'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                    {{ $owner['cash']['delta']['absolute'] >= 0 ? '▲' : '▼' }}
                    ₦{{ number_format(abs($owner['cash']['delta']['absolute']), 2) }}
                    @if($pct !== null) ({{ number_format($pct, 1) }}%) @endif
                </div>
            @endif
            <div class="text-[10px] text-gray-400 mt-1">
                Cash ₦{{ number_format($owner['cash']['cash']) }} ·
                POS ₦{{ number_format($owner['cash']['pos']) }} ·
                Transfer (verified) ₦{{ number_format($owner['cash']['transfers_verified']) }}
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 {{ $owner['gap']['widening'] ? 'ring-2 ring-red-400' : '' }}">
            <div class="flex items-center justify-between">
                <div class="text-xs font-semibold text-gray-500 uppercase">Gap</div>
                @if($owner['gap']['as_of'])
                    <span class="text-[10px] px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-500">as of {{ \Carbon\CarbonImmutable::parse($owner['gap']['as_of'])->format('M j') }}</span>
                @endif
            </div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white mt-1">₦{{ number_format($owner['gap']['value'], 2) }}</div>
            @if($owner['gap']['widening'])
                <div class="text-xs text-red-600 font-semibold mt-1">⚠ Widening for 2+ consecutive days</div>
            @else
                <div class="text-[10px] text-gray-400 mt-1">Earned vs collected divergence — stable</div>
            @endif
        </div>
    </div>

    {{-- ── Gap breakdown — tap-through to the explorer ────────────── --}}
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-4">
        @foreach([
            ['label' => 'Unverified Transfers', 'value' => $owner['gap_breakdown']['unverified_transfers'], 'tab' => 'sales'],
            ['label' => 'Open Folio Balances', 'value' => $owner['gap_breakdown']['open_folio_balance'], 'tab' => 'rooms'],
            ['label' => 'Unsettled Shifts', 'value' => $owner['gap_breakdown']['unsettled_shift_amount'], 'tab' => 'sales'],
            ['label' => 'Outstanding Staff Debt', 'value' => $owner['gap_breakdown']['staff_debt_outstanding'], 'tab' => 'debts'],
        ] as $item)
            <a href="{{ \App\Filament\Ceo\Pages\ReportExplorer::getUrl(['tab' => $item['tab'], 'preset' => $preset]) }}"
               class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 hover:border-primary-400 transition-colors">
                <div class="text-xs font-semibold text-gray-500 uppercase">{{ $item['label'] }}</div>
                <div class="text-lg font-bold text-gray-900 dark:text-white mt-1">₦{{ number_format($item['value'], 2) }}</div>
                <div class="text-[10px] text-primary-600 mt-1">View detail →</div>
            </a>
        @endforeach
    </div>

    {{-- ── Trend chart: Profit earned vs Cash collected ───────────── --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 mt-4">
        <div class="text-xs font-semibold text-gray-500 uppercase">Profit Earned vs Cash Collected</div>
        <div class="mt-3" wire:ignore>
            <div
                x-load
                x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
                data-chart-type="line"
                x-data="chart({
                    cachedData: @js([
                        'labels' => $owner['trend_chart']['labels'],
                        'datasets' => [
                            ['label' => 'Profit earned', 'data' => $owner['trend_chart']['profit'], 'borderColor' => '#10b981', 'backgroundColor' => 'transparent', 'tension' => 0.3],
                            ['label' => 'Cash collected', 'data' => $owner['trend_chart']['cash'], 'borderColor' => '#3b82f6', 'backgroundColor' => 'transparent', 'tension' => 0.3],
                        ],
                    ]),
                    options: @js(['plugins' => ['legend' => ['display' => true]]]),
                    type: @js('line'),
                })"
                class="fi-wi-chart-canvas-ctn"
                style="height: 220px"
            >
                <canvas x-ref="canvas"></canvas>
                <span x-ref="backgroundColorElement" class="fi-wi-chart-bg-color"></span>
                <span x-ref="borderColorElement" class="fi-wi-chart-border-color"></span>
                <span x-ref="gridColorElement" class="fi-wi-chart-grid-color"></span>
                <span x-ref="textColorElement" class="fi-wi-chart-text-color"></span>
            </div>
        </div>
    </div>

    {{-- ── Net position ─────────────────────────────────────────────── --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 mt-4">
        <div class="flex items-center justify-between">
            <div class="text-xs font-semibold text-gray-500 uppercase">Net Position</div>
            @if($owner['net_position']['indicative'])
                <span class="text-[10px] px-1.5 py-0.5 rounded bg-amber-100 dark:bg-amber-900 text-amber-700 dark:text-amber-300">indicative — lumpy daily entries</span>
            @endif
        </div>
        <div class="text-2xl font-bold {{ $owner['net_position']['value'] >= 0 ? 'text-emerald-600' : 'text-red-600' }} mt-1">
            ₦{{ number_format($owner['net_position']['value'], 2) }}
        </div>
        <div class="text-[10px] text-gray-400 mt-1">Gross profit − expenses, for the selected range</div>
    </div>

    {{-- ── Secondary panels: Expenses / Debts & Damages / Rooms ────── --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
            <div class="text-xs font-semibold text-gray-500 uppercase">Expenses</div>
            <div class="text-xl font-bold text-gray-900 dark:text-white mt-1">₦{{ number_format($owner['expenses']['total'], 2) }}</div>
            <div class="mt-2 space-y-1">
                @foreach($owner['expenses']['top_categories'] as $cat)
                    <div class="flex justify-between text-xs text-gray-500">
                        <span>{{ $cat['name'] }}</span><span>₦{{ number_format($cat['total'], 2) }}</span>
                    </div>
                @endforeach
            </div>
            <a href="{{ \App\Filament\Ceo\Pages\ReportExplorer::getUrl(['tab' => 'expenses', 'preset' => $preset]) }}" class="text-xs text-primary-600 hover:underline mt-2 inline-block">View Expenses →</a>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
            <div class="text-xs font-semibold text-gray-500 uppercase">Debts &amp; Damages</div>
            <div class="text-xs text-gray-500 mt-2 space-y-1">
                <div class="flex justify-between"><span>New debt (period)</span><span>₦{{ number_format($owner['debts_damages']['new'], 2) }}</span></div>
                <div class="flex justify-between"><span>Repaid (period)</span><span>₦{{ number_format($owner['debts_damages']['repaid'], 2) }}</span></div>
                <div class="flex justify-between"><span>Outstanding (now)</span><span>₦{{ number_format($owner['debts_damages']['outstanding'], 2) }}</span></div>
                <div class="flex justify-between"><span>Damages at cost (period)</span><span>₦{{ number_format($owner['debts_damages']['damages_cost'], 2) }}</span></div>
            </div>
            @if($owner['debts_damages']['pending_damage_approvals'] > 0)
                <div class="text-xs text-amber-600 font-semibold mt-2">{{ $owner['debts_damages']['pending_damage_approvals'] }} damage report(s) awaiting approval</div>
            @endif
            <a href="{{ \App\Filament\Ceo\Pages\ReportExplorer::getUrl(['tab' => 'debts', 'preset' => $preset]) }}" class="text-xs text-primary-600 hover:underline mt-2 inline-block">View Debts →</a>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
            <div class="text-xs font-semibold text-gray-500 uppercase">Rooms</div>
            <div class="text-xs text-gray-500 mt-2 space-y-1">
                <div class="flex justify-between"><span>Occupancy (as of {{ $owner['gap']['as_of'] ? \Carbon\CarbonImmutable::parse($owner['gap']['as_of'])->format('M j') : 'today' }})</span><span>{{ number_format($owner['rooms']['occupancy_rate'], 1) }}%</span></div>
                <div class="flex justify-between"><span>Room revenue (period)</span><span>₦{{ number_format($owner['rooms']['room_revenue'], 2) }}</span></div>
                <div class="flex justify-between"><span>ADR</span><span>₦{{ number_format($owner['rooms']['adr'], 2) }}</span></div>
                <div class="flex justify-between"><span>Open folio balances (now)</span><span>₦{{ number_format($owner['rooms']['open_folio_balance'], 2) }}</span></div>
            </div>
            <a href="{{ \App\Filament\Ceo\Pages\ReportExplorer::getUrl(['tab' => 'rooms', 'preset' => $preset]) }}" class="text-xs text-primary-600 hover:underline mt-2 inline-block">View Rooms →</a>
        </div>
    </div>

    {{-- ── Tier 1: Headline KPI strip ─────────────────────────────── --}}
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-4">
        {{-- Total Revenue --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
            <div class="text-xs font-semibold text-gray-500 uppercase">Total Revenue</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white mt-1">₦{{ number_format($d['tier1']['revenue']['value'], 2) }}</div>
            @if($d['tier1']['revenue']['delta'])
                @php($pct = $d['tier1']['revenue']['delta']['percent'])
                <div class="text-xs mt-1 {{ $d['tier1']['revenue']['delta']['absolute'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                    {{ $d['tier1']['revenue']['delta']['absolute'] >= 0 ? '▲' : '▼' }}
                    ₦{{ number_format(abs($d['tier1']['revenue']['delta']['absolute']), 2) }}
                    @if($pct !== null) ({{ number_format($pct, 1) }}%) @endif
                </div>
            @endif
            @if($d['unequal_length'])
                <div class="text-[11px] text-gray-400 mt-1">
                    ₦{{ number_format($d['tier1']['revenue']['per_day_avg'], 2) }}/day
                    vs ₦{{ number_format($d['tier1']['revenue']['comparison_per_day_avg'], 2) }}/day
                </div>
            @endif
            <div class="flex items-end gap-0.5 h-8 mt-2">
                @php($max = max(1, ...$d['tier1']['revenue']['sparkline']))
                @foreach($d['tier1']['revenue']['sparkline'] as $v)
                    <div class="flex-1 bg-primary-400 dark:bg-primary-600 rounded-sm" style="height: {{ max(2, ($v / $max) * 100) }}%"></div>
                @endforeach
            </div>
            <div class="text-[10px] text-gray-400 mt-1">14-day trend</div>
        </div>

        {{-- Gross Margin % --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
            <div class="text-xs font-semibold text-gray-500 uppercase">Gross Margin %</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white mt-1">{{ number_format($d['tier1']['margin_pct']['value'], 1) }}%</div>
            @if($d['tier1']['margin_pct']['delta'])
                <div class="text-xs mt-1 {{ $d['tier1']['margin_pct']['delta']['absolute'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                    {{ $d['tier1']['margin_pct']['delta']['absolute'] >= 0 ? '▲' : '▼' }}
                    {{ number_format(abs($d['tier1']['margin_pct']['delta']['absolute']), 1) }}pp
                </div>
            @endif
            <div class="text-[10px] text-gray-400 mt-2">Bar &amp; restaurant only, computed at current cost — not historical COGS.</div>
        </div>

        {{-- Occupancy Rate --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
            <div class="text-xs font-semibold text-gray-500 uppercase">Occupancy Rate</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white mt-1">{{ number_format($d['tier1']['occupancy_pct']['value'], 1) }}%</div>
            @if($d['tier1']['occupancy_pct']['delta'])
                <div class="text-xs mt-1 {{ $d['tier1']['occupancy_pct']['delta']['absolute'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                    {{ $d['tier1']['occupancy_pct']['delta']['absolute'] >= 0 ? '▲' : '▼' }}
                    {{ number_format(abs($d['tier1']['occupancy_pct']['delta']['absolute']), 1) }}pp
                </div>
            @endif
            <div class="flex items-end gap-0.5 h-8 mt-2">
                @php($maxOcc = max(1, ...$d['tier1']['occupancy_pct']['sparkline']))
                @foreach($d['tier1']['occupancy_pct']['sparkline'] as $v)
                    <div class="flex-1 bg-blue-400 dark:bg-blue-600 rounded-sm" style="height: {{ max(2, ($v / $maxOcc) * 100) }}%"></div>
                @endforeach
            </div>
            <div class="text-[10px] text-gray-400 mt-1">14-day trend</div>
        </div>

        {{-- Total Exposure --}}
        <div x-data="{ open: false }" class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 cursor-pointer" @click="open = !open">
            <div class="flex items-center justify-between">
                <div class="text-xs font-semibold text-gray-500 uppercase">Total Exposure</div>
                <span class="text-[10px] px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-500">as of now</span>
            </div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white mt-1">₦{{ number_format($d['tier1']['exposure']['total'], 2) }}</div>
            <div x-show="open" x-collapse class="mt-2 space-y-1 text-xs text-gray-500">
                <div class="flex justify-between"><span>Outstanding staff debt</span><span>₦{{ number_format($d['tier1']['exposure']['staff_debt'], 2) }}</span></div>
                <div class="flex justify-between"><span>Unverified transfers</span><span>₦{{ number_format($d['tier1']['exposure']['unverified_transfers'], 2) }}</span></div>
                <div class="flex justify-between"><span>In-house folio balances</span><span>₦{{ number_format($d['tier1']['exposure']['in_house_folio_balances'], 2) }}</span></div>
            </div>
        </div>
    </div>

    {{-- ── Tier 2: Risk & Exposure ────────────────────────────────── --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <div class="text-xs font-semibold text-gray-500 uppercase">Unverified Transfers</div>
                <span class="text-[10px] px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-500">as of now</span>
            </div>
            <div class="text-xl font-bold text-gray-900 dark:text-white mt-1">{{ $d['tier2']['unverified_transfers']['count'] }} · ₦{{ number_format($d['tier2']['unverified_transfers']['total'], 2) }}</div>
            @if($d['tier2']['unverified_transfers']['oldest_at'])
                <div class="text-xs text-gray-500 mt-1">Oldest: {{ \Illuminate\Support\Carbon::parse($d['tier2']['unverified_transfers']['oldest_at'])->diffForHumans() }}</div>
            @endif
            <a href="{{ \App\Filament\Ceo\Resources\Orders\OrderResource::getUrl() }}" class="text-xs text-primary-600 hover:underline mt-2 inline-block">View orders →</a>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <div class="text-xs font-semibold text-gray-500 uppercase">Outstanding Staff Debt</div>
                <span class="text-[10px] px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-500">as of now</span>
            </div>
            @php($aging = $d['tier2']['debt_aging'])
            @php($totalAging = max(0.01, array_sum($aging)))
            <div class="text-xl font-bold text-gray-900 dark:text-white mt-1">₦{{ number_format($totalAging, 2) }}</div>
            <div class="flex h-3 rounded overflow-hidden mt-2">
                <div class="bg-emerald-400" style="width: {{ $aging['aging_0_7'] / $totalAging * 100 }}%" title="0-7 days"></div>
                <div class="bg-amber-400" style="width: {{ $aging['aging_8_30'] / $totalAging * 100 }}%" title="8-30 days"></div>
                <div class="bg-red-400" style="width: {{ $aging['aging_30_plus'] / $totalAging * 100 }}%" title="30+ days"></div>
            </div>
            <div class="text-[10px] text-gray-400 mt-1">0–7d ₦{{ number_format($aging['aging_0_7']) }} · 8–30d ₦{{ number_format($aging['aging_8_30']) }} · 30+d ₦{{ number_format($aging['aging_30_plus']) }}</div>
            <a href="{{ \App\Filament\Ceo\Pages\LeakageReport::getUrl() }}" class="text-xs text-primary-600 hover:underline mt-2 inline-block">View Leakage Report →</a>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <div class="text-xs font-semibold text-gray-500 uppercase">In-House Folio Balances</div>
                <span class="text-[10px] px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-500">as of now</span>
            </div>
            <div class="text-xl font-bold text-gray-900 dark:text-white mt-1">₦{{ number_format($d['tier2']['in_house_folio_balances'], 2) }}</div>
            <a href="{{ \App\Filament\Ceo\Resources\Folios\FolioResource::getUrl() }}" class="text-xs text-primary-600 hover:underline mt-2 inline-block">View folios →</a>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
            <div class="text-xs font-semibold text-gray-500 uppercase">Voids (period)</div>
            <div class="text-xl font-bold text-gray-900 dark:text-white mt-1">{{ $d['tier2']['voids']['count'] }} · ₦{{ number_format($d['tier2']['voids']['value'], 2) }}</div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
            <div class="text-xs font-semibold text-gray-500 uppercase">Discrepancy Resolutions (period)</div>
            <div class="text-xl font-bold text-gray-900 dark:text-white mt-1">{{ $d['tier2']['discrepancy_resolutions']['count'] }} · ₦{{ number_format($d['tier2']['discrepancy_resolutions']['value'], 2) }}</div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <div class="text-xs font-semibold text-gray-500 uppercase">Stock Alerts</div>
                <span class="text-[10px] px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-500">as of now</span>
            </div>
            <div class="text-xl font-bold text-gray-900 dark:text-white mt-1">
                <span class="text-amber-600">{{ $d['tier2']['stock']['low'] }} low</span> ·
                <span class="text-red-600">{{ $d['tier2']['stock']['sold_out'] }} sold out</span>
            </div>
            <div class="text-xs text-gray-500 mt-1">Stock value at cost: ₦{{ number_format($d['tier2']['stock']['total_value_at_cost'], 2) }}</div>
            <a href="{{ \App\Filament\Ceo\Pages\StockAlerts::getUrl() }}" class="text-xs text-primary-600 hover:underline mt-2 inline-block">View Stock Alerts →</a>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
            <div class="text-xs font-semibold text-gray-500 uppercase">Operational Integrity</div>
            <div class="text-sm text-gray-700 dark:text-gray-300 mt-2">Unsealed handovers past expected time: <span class="font-bold">{{ $d['tier2']['unsealed_handovers'] }}</span></div>
            <div class="text-sm text-gray-700 dark:text-gray-300">Shifts open beyond normal duration: <span class="font-bold">{{ $d['tier2']['stale_shifts'] }}</span></div>
        </div>
    </div>

    {{-- ── Tier 3: Drivers ────────────────────────────────────────── --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-4">
        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700" x-data="{ showFolio: false }">
            <div class="flex items-center justify-between">
                <div class="text-xs font-semibold text-gray-500 uppercase">Revenue Mix</div>
                <button type="button" class="text-xs text-primary-600" @click="showFolio = !showFolio">Toggle billed-via-folio</button>
            </div>
            @php($mix = $d['tier3']['revenue_mix'])
            <div class="mt-3 space-y-2">
                @foreach(['bar' => 'Bar', 'restaurant' => 'Restaurant', 'rooms' => 'Rooms'] as $key => $label)
                    <div>
                        <div class="flex justify-between text-xs"><span>{{ $label }}</span><span>₦{{ number_format($mix[$key], 2) }}</span></div>
                        <div class="h-2 bg-gray-100 dark:bg-gray-700 rounded overflow-hidden mt-0.5">
                            <div class="h-full bg-primary-500" style="width: {{ $mix['total'] > 0 ? $mix[$key] / $mix['total'] * 100 : 0 }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
            @if($d['tier3']['comparison_revenue_mix'])
                <div class="text-[11px] text-gray-400 mt-2">
                    Comparison — Bar ₦{{ number_format($d['tier3']['comparison_revenue_mix']['bar'], 2) }},
                    Restaurant ₦{{ number_format($d['tier3']['comparison_revenue_mix']['restaurant'], 2) }},
                    Rooms ₦{{ number_format($d['tier3']['comparison_revenue_mix']['rooms'], 2) }}
                </div>
            @endif
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
            <div class="text-xs font-semibold text-gray-500 uppercase">Payment Mix Trend</div>
            <div class="mt-3 space-y-1">
                @foreach($d['tier3']['payment_mix_series'] as $day)
                    @php($dayTotal = max(0.01, array_sum($day['by_method'])))
                    <div class="flex items-center gap-2">
                        <span class="text-[10px] text-gray-400 w-12">{{ $day['date']->format('M j') }}</span>
                        <div class="flex-1 h-3 rounded overflow-hidden flex bg-gray-100 dark:bg-gray-700">
                            @foreach(['cash' => 'bg-emerald-400', 'pos' => 'bg-blue-400', 'transfer' => 'bg-amber-400', 'split' => 'bg-purple-400'] as $m => $color)
                                @if(!empty($day['by_method'][$m]))
                                    <div class="{{ $color }}" style="width: {{ $day['by_method'][$m] / $dayTotal * 100 }}%" title="{{ ucfirst($m) }}: ₦{{ number_format($day['by_method'][$m], 2) }}"></div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="flex gap-3 text-[10px] text-gray-400 mt-2">
                <span><span class="inline-block w-2 h-2 bg-emerald-400 rounded-sm"></span> Cash</span>
                <span><span class="inline-block w-2 h-2 bg-blue-400 rounded-sm"></span> POS</span>
                <span><span class="inline-block w-2 h-2 bg-amber-400 rounded-sm"></span> Transfer</span>
                <span><span class="inline-block w-2 h-2 bg-purple-400 rounded-sm"></span> Split</span>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 lg:col-span-2">
            <div class="text-xs font-semibold text-gray-500 uppercase">Top 10 Products by Revenue</div>
            <table class="w-full text-sm mt-2">
                <thead>
                    <tr class="text-left text-xs text-gray-500 border-b border-gray-200 dark:border-gray-700">
                        <th class="py-1">Item</th>
                        <th class="py-1 text-right">Qty</th>
                        <th class="py-1 text-right">Revenue</th>
                        <th class="py-1 text-right">Margin %</th>
                    </tr>
                </thead>
                <tbody>
                    @php($categoryAvgMargin = $d['tier3']['top_products']->groupBy('category_name')->map(fn($g) => $g->avg('margin_pct')))
                    @foreach($d['tier3']['top_products'] as $row)
                        <tr class="border-b border-gray-100 dark:border-gray-700/50">
                            <td class="py-1">{{ $row['item_name'] }}</td>
                            <td class="py-1 text-right">{{ number_format($row['quantity']) }}</td>
                            <td class="py-1 text-right">₦{{ number_format($row['revenue'], 2) }}</td>
                            <td class="py-1 text-right {{ $row['margin_pct'] < ($categoryAvgMargin[$row['category_name']] ?? 0) ? 'text-red-600 font-semibold' : '' }}">
                                {{ number_format($row['margin_pct'], 1) }}%
                                @if($row['margin_pct'] < ($categoryAvgMargin[$row['category_name']] ?? 0))
                                    <span title="Below category average margin">⚠</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 lg:col-span-2">
            <div class="text-xs font-semibold text-gray-500 uppercase">Daily Revenue</div>
            <div class="flex items-end gap-1 h-32 mt-3">
                @php($maxDaily = max(1, $d['tier3']['daily_revenue']->max('revenue'), $d['tier3']['daily_revenue_comparison']?->max('revenue') ?? 0))
                @foreach($d['tier3']['daily_revenue'] as $i => $day)
                    <div class="flex-1 flex flex-col items-center justify-end h-full relative group">
                        @if($d['tier3']['daily_revenue_comparison'] && $d['tier3']['daily_revenue_comparison']->get($i))
                            <div class="w-full border-t-2 border-dashed border-gray-400 absolute" style="bottom: {{ ($d['tier3']['daily_revenue_comparison'][$i]['revenue'] / $maxDaily) * 100 }}%"></div>
                        @endif
                        <div class="w-2/3 bg-primary-500 rounded-t-sm" style="height: {{ max(2, ($day['revenue'] / $maxDaily) * 100) }}%" title="₦{{ number_format($day['revenue'], 2) }}"></div>
                        <span class="text-[9px] text-gray-400 mt-1">{{ $day['date']->format('j') }}</span>
                    </div>
                @endforeach
            </div>
            <div class="text-[10px] text-gray-400 mt-1">Dashed line = comparison period (ghost)</div>
        </div>
    </div>
</x-filament-panels::page>
