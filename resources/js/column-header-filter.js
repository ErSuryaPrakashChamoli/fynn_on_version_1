/*
 * Excel-style column header filters for every Filament listing.
 *
 * Each table renders one shared popover (filament/components/column-header-filters.blade.php)
 * whose Alpine component is defined here; the funnel in every filterable
 * header cell (filament/components/column-header-filter-cell.blade.php)
 * dispatches `fynn-header-filter-toggle` with the column it belongs to.
 *
 * The popover talks to the listing's Livewire component through the
 * HasColumnHeaderFilters trait:
 *   getTableHeaderFilterOptions(column, search?)  renderless, lists values
 *   applyTableHeaderFilter(column, state|null)    applies / clears
 *   removeTableHeaderFilter(column)
 *   sortTable(column, direction)                  Filament's own
 *
 * Values come back as opaque tokens; the popover never needs to read them,
 * only to send back the ones that are ticked.
 *
 * Registered through FilamentAsset in AdminPanelProvider::boot(): run
 * `php artisan filament:assets` after editing this file.
 */
(() => {
    'use strict';

    if (window.__fynnColumnHeaderFilterRegistered) {
        return;
    }

    window.__fynnColumnHeaderFilterRegistered = true;

    const PANEL_WIDTH = 304;
    const GAP = 6;
    const EDGE = 8;
    const PREFERRED_HEIGHT = 300;

    const pad = (number) => String(number).padStart(2, '0');

    const isoDate = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;

    const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    function columnHeaderFilter({ tableId }) {
        return {
            tableId,

            open: false,
            column: null,
            label: '',
            kind: 'values',
            sortable: false,
            sortDirection: null,
            active: false,
            trigger: null,
            panelStyle: '',

            loading: false,
            message: null,
            requestId: 0,

            options: [],
            truncated: false,
            blanks: null,
            blanksChecked: false,

            search: '',
            searchResults: null,
            searchLoading: false,
            searchSelection: {},

            from: '',
            to: '',
            bounds: { min: null, max: null },

            contains: '',

            init() {
                this.onViewportChange = () => this.reposition();
            },

            destroy() {
                this.stopFollowingViewport();
            },

            toggle(detail) {
                if (! detail || detail.tableId !== this.tableId) {
                    // Another table's funnel: only one popover open at a time.
                    this.close(false);

                    return;
                }

                if (this.open && this.column === detail.column) {
                    this.close(true);

                    return;
                }

                this.show(detail);
            },

            show(detail) {
                this.column = detail.column;
                this.label = detail.label;
                this.kind = detail.kind;
                this.sortable = !! detail.sortable;
                this.sortDirection = detail.sortDirection ?? null;
                this.active = !! detail.active;
                this.trigger = detail.trigger ?? null;

                this.message = null;
                this.options = [];
                this.truncated = false;
                this.blanks = null;
                this.blanksChecked = false;
                this.search = '';
                this.searchResults = null;
                this.searchLoading = false;
                this.searchSelection = {};
                this.from = '';
                this.to = '';
                this.bounds = { min: null, max: null };
                this.contains = '';

                this.open = true;
                this.followViewport();

                // A sort-only column has nothing to load.
                if (! this.hasFilter) {
                    this.requestId++;
                    this.loading = false;

                    this.$nextTick(() => {
                        this.reposition();
                        this.focusFirstField();
                    });

                    return;
                }

                this.$nextTick(() => this.reposition());

                this.load();
            },

            get hasFilter() {
                return this.kind !== 'sort';
            },

            async load() {
                const requestId = ++this.requestId;

                this.loading = true;

                try {
                    const data = await this.$wire.getTableHeaderFilterOptions(this.column);

                    if (requestId !== this.requestId) {
                        return;
                    }

                    this.receive(data);
                } catch (error) {
                    if (requestId === this.requestId) {
                        this.message = 'The values could not be loaded. Please try again.';
                    }
                } finally {
                    if (requestId === this.requestId) {
                        this.loading = false;

                        this.$nextTick(() => {
                            this.reposition();
                            this.focusFirstField();
                        });
                    }
                }
            },

            receive(data) {
                if (! data || ! data.available) {
                    this.message = data?.message ?? 'This column cannot be filtered.';

                    return;
                }

                const state = data.state ?? null;

                this.kind = data.kind;

                if (this.isChecklist) {
                    this.options = (data.options ?? []).map((option) => ({
                        label: option.label,
                        values: option.values,
                        checked: !! option.checked,
                    }));
                    this.truncated = !! data.truncated;
                    this.blanks = data.blanks ?? null;
                    this.blanksChecked = data.blanks ? !! data.blanks.checked : false;

                    if (state && state.contains) {
                        this.search = state.contains;
                        this.onSearch();
                    }

                    return;
                }

                if (this.kind === 'date') {
                    this.from = state?.from ?? '';
                    this.to = state?.to ?? '';
                    this.bounds = data.bounds ?? { min: null, max: null };

                    return;
                }

                this.contains = state?.contains ?? '';
            },

            get isChecklist() {
                return this.kind === 'values' || this.kind === 'options';
            },

            get searching() {
                return this.search.trim() !== '';
            },

            get visibleOptions() {
                if (! this.searching) {
                    return this.options;
                }

                if (this.searchResults) {
                    return this.searchResults;
                }

                const needle = this.search.trim().toLowerCase();

                return this.options.filter((option) => option.label.toLowerCase().includes(needle));
            },

            isChecked(option) {
                // While searching every match starts ticked, as in Excel.
                return this.searching
                    ? this.searchSelection[option.label] !== false
                    : option.checked;
            },

            setChecked(option, checked) {
                if (this.searching) {
                    this.searchSelection[option.label] = checked;

                    return;
                }

                option.checked = checked;
            },

            get allChecked() {
                const options = this.visibleOptions;
                const includesBlanks = ! this.searching && this.blanks;

                if (! options.length && ! includesBlanks) {
                    return false;
                }

                return options.every((option) => this.isChecked(option))
                    && (! includesBlanks || this.blanksChecked);
            },

            get someChecked() {
                return this.visibleOptions.some((option) => this.isChecked(option))
                    || (! this.searching && !! this.blanks && this.blanksChecked);
            },

            toggleAll(checked) {
                this.visibleOptions.forEach((option) => this.setChecked(option, checked));

                if (! this.searching && this.blanks) {
                    this.blanksChecked = checked;
                }
            },

            onSearch() {
                this.searchSelection = {};

                // A complete list is searched here; a cut-off one on the server.
                if (this.kind !== 'values' || ! this.truncated || ! this.searching) {
                    this.searchResults = null;
                    this.searchLoading = false;

                    return;
                }

                const requestId = ++this.requestId;
                const search = this.search.trim();

                this.searchLoading = true;

                this.$wire.getTableHeaderFilterOptions(this.column, search)
                    .then((data) => {
                        if (requestId !== this.requestId) {
                            return;
                        }

                        this.searchResults = (data?.options ?? []).map((option) => ({
                            label: option.label,
                            values: option.values,
                            checked: true,
                        }));
                    })
                    .catch(() => {
                        if (requestId === this.requestId) {
                            this.searchResults = [];
                        }
                    })
                    .finally(() => {
                        if (requestId === this.requestId) {
                            this.searchLoading = false;
                        }
                    });
            },

            get canApply() {
                if (this.loading || this.message || ! this.column) {
                    return false;
                }

                if (! this.isChecklist) {
                    return true;
                }

                if (this.searching) {
                    return ! this.searchLoading && this.someChecked;
                }

                return this.someChecked;
            },

            /**
             * The state sent to applyTableHeaderFilter(); null clears it.
             */
            buildState() {
                if (this.kind === 'date') {
                    return (this.from || this.to)
                        ? { from: this.from || null, to: this.to || null }
                        : null;
                }

                if (this.kind === 'text') {
                    const contains = this.contains.trim();

                    return contains ? { contains } : null;
                }

                if (this.searching) {
                    const visible = this.visibleOptions;
                    const chosen = visible.filter((option) => this.isChecked(option));

                    // Every match of a cut-off list is ticked: keep "contains"
                    // so matches beyond the listed ones count too.
                    if (this.kind === 'values' && this.truncated && chosen.length === visible.length) {
                        return { contains: this.search.trim() };
                    }

                    return { values: chosen.flatMap((option) => option.values) };
                }

                const chosen = this.options.filter((option) => option.checked);
                const blanks = !! this.blanks && this.blanksChecked;

                if (chosen.length === this.options.length && (! this.blanks || blanks)) {
                    return null;
                }

                return { values: chosen.flatMap((option) => option.values), blanks };
            },

            apply() {
                if (! this.canApply) {
                    return;
                }

                const column = this.column;
                const state = this.buildState();

                this.close(false);

                this.$wire.applyTableHeaderFilter(column, state);
            },

            clearFilter() {
                const column = this.column;

                this.close(false);

                this.$wire.removeTableHeaderFilter(column);
            },

            sort(direction) {
                const column = this.column;

                this.close(false);

                this.$wire.sortTable(column, direction);
            },

            removeSort() {
                this.close(false);

                // Back to the table's default order; Filament's
                // updatedTableSort() hook persists it and resets the page.
                this.$wire.$set('tableSort', null);
            },

            sortLabel(direction) {
                if (this.kind === 'date') {
                    return direction === 'asc' ? 'Sort oldest to newest' : 'Sort newest to oldest';
                }

                return direction === 'asc' ? 'Sort A to Z' : 'Sort Z to A';
            },

            preset(name) {
                const today = new Date();
                const shift = (days) => new Date(today.getFullYear(), today.getMonth(), today.getDate() + days);

                const ranges = {
                    today: [today, today],
                    yesterday: [shift(-1), shift(-1)],
                    last7: [shift(-6), today],
                    thisMonth: [new Date(today.getFullYear(), today.getMonth(), 1), today],
                    lastMonth: [
                        new Date(today.getFullYear(), today.getMonth() - 1, 1),
                        new Date(today.getFullYear(), today.getMonth(), 0),
                    ],
                };

                const [from, to] = ranges[name] ?? [today, today];

                this.from = isoDate(from);
                this.to = isoDate(to);
            },

            displayDate(value) {
                const [year, month, day] = String(value ?? '').split('-');

                return year && month && day ? `${day} ${MONTHS[Number(month) - 1]} ${year}` : '';
            },

            close(returnFocus = false) {
                if (! this.open) {
                    return;
                }

                this.open = false;
                this.requestId++;
                this.loading = false;
                this.searchLoading = false;
                this.stopFollowingViewport();

                if (returnFocus && this.trigger?.isConnected) {
                    this.trigger.focus();
                }
            },

            onOutsideClick(event) {
                // A funnel click toggles through its own event.
                if (event.target?.closest?.('[data-fynn-header-filter-trigger]')) {
                    return;
                }

                this.close(false);
            },

            focusFirstField() {
                const panel = this.$refs.panel;

                (panel?.querySelector('[data-fynn-hf-autofocus]') ?? panel?.querySelector('.fynn-hf-menu-item'))
                    ?.focus({ preventScroll: true });
            },

            followViewport() {
                window.addEventListener('scroll', this.onViewportChange, true);
                window.addEventListener('resize', this.onViewportChange);
            },

            stopFollowingViewport() {
                window.removeEventListener('scroll', this.onViewportChange, true);
                window.removeEventListener('resize', this.onViewportChange);
            },

            /**
             * Fixed under (or, near the bottom of the screen, above) the
             * funnel; follows it while the page or table scrolls.
             */
            reposition() {
                if (! this.open) {
                    return;
                }

                if (! this.trigger?.isConnected) {
                    // A re-render replaced the header: find this column's new funnel.
                    const root = this.$root.closest('[wire\\:id]') ?? document;
                    const selector = `[data-fynn-header-filter-trigger][data-column="${CSS.escape(this.column ?? '')}"]`;

                    this.trigger = root.querySelector(selector);

                    if (! this.trigger) {
                        this.close(false);

                        return;
                    }
                }

                const rect = this.trigger.getBoundingClientRect();
                const viewportWidth = window.innerWidth;
                const viewportHeight = window.innerHeight;
                const width = Math.min(PANEL_WIDTH, viewportWidth - (EDGE * 2));
                const left = Math.min(Math.max(EDGE, rect.left - 12), viewportWidth - width - EDGE);

                const spaceBelow = viewportHeight - rect.bottom - GAP - EDGE;
                const spaceAbove = rect.top - GAP - EDGE;
                const opensUp = spaceBelow < PREFERRED_HEIGHT && spaceAbove > spaceBelow;
                const maxHeight = Math.max(180, Math.min(520, opensUp ? spaceAbove : spaceBelow));

                const vertical = opensUp
                    ? `bottom: ${Math.round(viewportHeight - rect.top + GAP)}px;`
                    : `top: ${Math.round(rect.bottom + GAP)}px;`;

                this.panelStyle = `left: ${Math.round(left)}px; width: ${Math.round(width)}px; max-height: ${Math.round(maxHeight)}px; ${vertical}`;
            },
        };
    }

    const register = () => window.Alpine.data('fynnColumnHeaderFilter', columnHeaderFilter);

    if (window.Alpine) {
        register();
    } else {
        document.addEventListener('alpine:init', register);
    }
})();
