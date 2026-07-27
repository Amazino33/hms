{{-- Sidebar navigation search — filters the long list of page links in the
     sidebar as you type, so you don't have to scroll through every group
     (Cashier, Inventory, Menu Management, ...) to find one page. This is
     NOT Filament's own Global Search (disabled in this panel) — that
     searches records inside your data; this only filters page links. --}}
<div class="px-3 pt-3 pb-1">
    <div class="relative">
        <svg class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 10.5a6.5 6.5 0 11-13 0 6.5 6.5 0 0113 0z" />
        </svg>
        <input
            type="text"
            id="fi-sidebar-nav-search-input"
            placeholder="Search menu…"
            autocomplete="off"
            class="fi-input block w-full rounded-lg border-none bg-gray-100 py-1.5 pl-8 pr-7 text-sm text-gray-950 placeholder:text-gray-400 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500"
        />
        <button
            type="button"
            id="fi-sidebar-nav-search-clear"
            class="absolute right-2 top-1/2 hidden -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
            title="Clear"
        >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>
</div>

<script>
    (function () {
        var input = document.getElementById('fi-sidebar-nav-search-input');
        var clearBtn = document.getElementById('fi-sidebar-nav-search-clear');

        // The sidebar persists across SPA navigations (Filament's ->spa()),
        // so this only needs to bind once — but guard anyway in case a full
        // page load ever re-runs this script against the same markup.
        if (!input || input.dataset.navSearchBound) return;
        input.dataset.navSearchBound = '1';

        function applyFilter(query) {
            query = query.trim().toLowerCase();
            clearBtn.classList.toggle('hidden', query === '');

            document.querySelectorAll('.fi-sidebar-group').forEach(function (group) {
                var groupLabelEl = group.querySelector('.fi-sidebar-group-label');
                var groupLabel = groupLabelEl ? groupLabelEl.textContent.trim().toLowerCase() : '';
                var groupLabelMatches = query === '' || groupLabel.indexOf(query) !== -1;
                var itemsWrap = group.querySelector('.fi-sidebar-group-items');
                var anyItemMatches = false;

                group.querySelectorAll('.fi-sidebar-item').forEach(function (item) {
                    var labelEl = item.querySelector('.fi-sidebar-item-label');
                    var label = labelEl ? labelEl.textContent.trim().toLowerCase() : '';
                    var itemMatches = query === '' || groupLabelMatches || label.indexOf(query) !== -1;

                    item.style.display = itemMatches ? '' : 'none';
                    if (itemMatches && query !== '') anyItemMatches = true;
                });

                group.style.display = (query === '' || groupLabelMatches || anyItemMatches) ? '' : 'none';

                if (!itemsWrap) return;

                if (query !== '' && (groupLabelMatches || anyItemMatches)) {
                    // Force the group open so a match hiding inside a
                    // collapsed group is actually visible.
                    itemsWrap.style.display = '';
                    group.classList.remove('fi-collapsed');
                } else if (query === '') {
                    // Restore whatever the user's own collapse preference was.
                    var collapsedGroups = JSON.parse(localStorage.getItem('collapsedGroups') || '[]');
                    var isCollapsed = collapsedGroups.indexOf(group.dataset.groupLabel) !== -1;
                    itemsWrap.style.display = isCollapsed ? 'none' : '';
                    group.classList.toggle('fi-collapsed', isCollapsed);
                }
            });
        }

        input.addEventListener('input', function () {
            applyFilter(input.value);
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                input.value = '';
                applyFilter('');
                input.blur();
            }
        });

        clearBtn.addEventListener('click', function () {
            input.value = '';
            applyFilter('');
            input.focus();
        });
    })();
</script>
