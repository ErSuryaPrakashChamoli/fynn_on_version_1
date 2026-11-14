{{--
    Rendered once per table under its toolbar (tables::toolbar.after hook,
    App\Support\ColumnHeaderFilters\ColumnHeaderFilters::renderToolbar()):

    1. the active column filters as removable chips — they stay reachable
       even when a filter leaves no rows and Filament drops the header row;
    2. the Excel-style popover every funnel in this table's header opens.
       It is wire:ignore'd (Alpine owns it) and teleported to <body> so the
       table's horizontal scroll box cannot clip it. Behaviour lives in
       resources/js/column-header-filter.js, styles in
       resources/css/column-header-filters.css (both FilamentAssets — run
       `php artisan filament:assets` after editing either).
--}}
@php
    use Illuminate\Support\Js;
@endphp

<div class="fynn-hf-toolbar">
    @if (count($indicators))
        <div class="fi-ta-filter-indicators fynn-hf-indicators">
            <div>
                <span class="fi-ta-filter-indicators-label">
                    Column filters
                </span>

                <div class="fi-ta-filter-indicators-badges-ctn">
                    @foreach ($indicators as $columnName => $indicator)
                        <x-filament::badge color="primary">
                            {{ $indicator }}

                            <x-slot
                                name="deleteButton"
                                label="Remove this column filter"
                                :wire:click="'removeTableHeaderFilter(' . Js::from($columnName) . ')'"
                                wire:loading.attr="disabled"
                                wire:target="removeTableHeaderFilter,removeTableHeaderFilters"
                            ></x-slot>
                        </x-filament::badge>
                    @endforeach
                </div>
            </div>

            <button
                type="button"
                wire:click="removeTableHeaderFilters"
                wire:loading.attr="disabled"
                wire:target="removeTableHeaderFilters"
                class="fynn-hf-clear-all"
            >
                Clear column filters
            </button>
        </div>
    @endif

    <div
        wire:ignore
        x-data="fynnColumnHeaderFilter({ tableId: @js($livewire->getId()) })"
        x-on:fynn-header-filter-toggle.window="toggle($event.detail)"
        class="fynn-hf-host"
    >
        <template x-teleport="body">
            <div
                x-cloak
                x-show="open"
                x-ref="panel"
                x-on:keydown.escape.prevent.stop="close(true)"
                x-on:click.outside="onOutsideClick($event)"
                x-bind:style="panelStyle"
                x-bind:aria-label="'Filter ' + label"
                role="dialog"
                class="fynn-hf-panel"
            >
                <div class="fynn-hf-panel-header">
                    <span class="fynn-hf-panel-title" x-text="label"></span>

                    <button
                        type="button"
                        x-on:click="close(true)"
                        class="fynn-hf-close"
                        aria-label="Close"
                    >
                        &times;
                    </button>
                </div>

                <div class="fynn-hf-menu" x-show="sortable || active">
                    <template x-if="sortable">
                        <div>
                            <button
                                type="button"
                                x-on:click="sort('asc')"
                                x-bind:class="{ 'fynn-hf-menu-item-current': sortDirection === 'asc' }"
                                class="fynn-hf-menu-item"
                            >
                                <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M2 3.75A.75.75 0 0 1 2.75 3h11.5a.75.75 0 0 1 0 1.5H2.75A.75.75 0 0 1 2 3.75ZM2 7.5a.75.75 0 0 1 .75-.75h6.365a.75.75 0 0 1 0 1.5H2.75A.75.75 0 0 1 2 7.5ZM14 7a.75.75 0 0 1 .55.24l3.25 3.5a.75.75 0 1 1-1.1 1.02l-1.95-2.1v6.59a.75.75 0 0 1-1.5 0V9.66l-1.95 2.1a.75.75 0 1 1-1.1-1.02l3.25-3.5A.75.75 0 0 1 14 7ZM2 11.25a.75.75 0 0 1 .75-.75H7A.75.75 0 0 1 7 12H2.75a.75.75 0 0 1-.75-.75Z" clip-rule="evenodd" /></svg>
                                <span x-text="sortLabel('asc')"></span>
                            </button>

                            <button
                                type="button"
                                x-on:click="sort('desc')"
                                x-bind:class="{ 'fynn-hf-menu-item-current': sortDirection === 'desc' }"
                                class="fynn-hf-menu-item"
                            >
                                <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M2 3.75A.75.75 0 0 1 2.75 3h11.5a.75.75 0 0 1 0 1.5H2.75A.75.75 0 0 1 2 3.75ZM2 7.5a.75.75 0 0 1 .75-.75h7.508a.75.75 0 0 1 0 1.5H2.75A.75.75 0 0 1 2 7.5ZM14 7a.75.75 0 0 1 .75.75v6.59l1.95-2.1a.75.75 0 1 1 1.1 1.02l-3.25 3.5a.75.75 0 0 1-1.1 0l-3.25-3.5a.75.75 0 1 1 1.1-1.02l1.95 2.1V7.75A.75.75 0 0 1 14 7ZM2 11.25a.75.75 0 0 1 .75-.75h4.562a.75.75 0 0 1 0 1.5H2.75a.75.75 0 0 1-.75-.75Z" clip-rule="evenodd" /></svg>
                                <span x-text="sortLabel('desc')"></span>
                            </button>

                            <template x-if="sortDirection">
                                <button
                                    type="button"
                                    x-on:click="removeSort()"
                                    class="fynn-hf-menu-item"
                                >
                                    <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" /></svg>
                                    <span>Remove sort</span>
                                </button>
                            </template>
                        </div>
                    </template>

                    <template x-if="active">
                        <button
                            type="button"
                            x-on:click="clearFilter()"
                            class="fynn-hf-menu-item fynn-hf-menu-item-danger"
                        >
                            <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" /></svg>
                            <span x-text="'Clear filter from “' + label + '”'"></span>
                        </button>
                    </template>
                </div>

                <div class="fynn-hf-body" x-show="hasFilter">
                    <p x-show="loading" class="fynn-hf-status">Loading values…</p>

                    <p x-show="! loading && message" x-text="message" class="fynn-hf-status"></p>

                    {{-- Checklist: the column's values, or its select filter's options --}}
                    <template x-if="! loading && ! message && isChecklist">
                        <div class="fynn-hf-checklist">
                            <label class="fynn-hf-search">
                                <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11ZM2 9a7 7 0 1 1 12.452 4.391l3.328 3.329a.75.75 0 1 1-1.06 1.06l-3.329-3.328A7 7 0 0 1 2 9Z" clip-rule="evenodd" /></svg>

                                <input
                                    type="search"
                                    x-model="search"
                                    x-on:input.debounce.300ms="onSearch()"
                                    x-on:keydown.enter.prevent="apply()"
                                    placeholder="Search"
                                    aria-label="Search values"
                                    autocomplete="off"
                                    data-fynn-hf-autofocus
                                />
                            </label>

                            <div class="fynn-hf-list" role="group" x-bind:aria-label="label + ' values'">
                                <label class="fynn-hf-option fynn-hf-option-all" x-show="visibleOptions.length || (blanks && ! searching)">
                                    <input
                                        type="checkbox"
                                        x-bind:checked="allChecked"
                                        x-effect="$el.indeterminate = someChecked && ! allChecked"
                                        x-on:change="toggleAll($event.target.checked)"
                                    />
                                    <span x-text="searching ? '(Select all search results)' : '(Select all)'"></span>
                                </label>

                                <template x-for="option in visibleOptions" x-bind:key="option.label">
                                    <label class="fynn-hf-option">
                                        <input
                                            type="checkbox"
                                            x-bind:checked="isChecked(option)"
                                            x-on:change="setChecked(option, $event.target.checked)"
                                        />
                                        <span x-text="option.label" x-bind:title="option.label"></span>
                                    </label>
                                </template>

                                <template x-if="blanks && ! searching">
                                    <label class="fynn-hf-option fynn-hf-option-blanks">
                                        <input type="checkbox" x-model="blanksChecked" />
                                        <span>(Blanks)</span>
                                    </label>
                                </template>

                                <p x-show="searchLoading" class="fynn-hf-status">Searching…</p>

                                <p
                                    x-show="! searchLoading && ! visibleOptions.length && ! (blanks && ! searching)"
                                    class="fynn-hf-status"
                                >
                                    No matching values
                                </p>
                            </div>

                            <p x-show="truncated && ! searching" class="fynn-hf-hint">
                                Showing the first values only — type to search for others.
                            </p>

                            <p x-show="truncated && searching && ! searchLoading && allChecked" class="fynn-hf-hint">
                                OK keeps every row containing “<span x-text="search.trim()"></span>”.
                            </p>
                        </div>
                    </template>

                    {{-- Date range --}}
                    <template x-if="! loading && ! message && kind === 'date'">
                        <div class="fynn-hf-dates">
                            <div class="fynn-hf-date-fields">
                                <label>
                                    <span>From</span>
                                    <input
                                        type="date"
                                        x-model="from"
                                        x-bind:min="bounds.min"
                                        x-bind:max="to || bounds.max"
                                        x-on:keydown.enter.prevent="apply()"
                                        data-fynn-hf-autofocus
                                    />
                                </label>

                                <label>
                                    <span>To</span>
                                    <input
                                        type="date"
                                        x-model="to"
                                        x-bind:min="from || bounds.min"
                                        x-bind:max="bounds.max"
                                        x-on:keydown.enter.prevent="apply()"
                                    />
                                </label>
                            </div>

                            <div class="fynn-hf-presets">
                                <button type="button" x-on:click="preset('today')">Today</button>
                                <button type="button" x-on:click="preset('yesterday')">Yesterday</button>
                                <button type="button" x-on:click="preset('last7')">Last 7 days</button>
                                <button type="button" x-on:click="preset('thisMonth')">This month</button>
                                <button type="button" x-on:click="preset('lastMonth')">Last month</button>
                            </div>

                            <p x-show="bounds.min && bounds.max" class="fynn-hf-hint">
                                Rows run from <span x-text="displayDate(bounds.min)"></span>
                                to <span x-text="displayDate(bounds.max)"></span>.
                            </p>
                        </div>
                    </template>

                    {{-- Contains, for computed columns --}}
                    <template x-if="! loading && ! message && kind === 'text'">
                        <div class="fynn-hf-text">
                            <label class="fynn-hf-search">
                                <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11ZM2 9a7 7 0 1 1 12.452 4.391l3.328 3.329a.75.75 0 1 1-1.06 1.06l-3.329-3.328A7 7 0 0 1 2 9Z" clip-rule="evenodd" /></svg>

                                <input
                                    type="search"
                                    x-model="contains"
                                    x-on:keydown.enter.prevent="apply()"
                                    placeholder="Contains…"
                                    x-bind:aria-label="label + ' contains'"
                                    autocomplete="off"
                                    data-fynn-hf-autofocus
                                />
                            </label>

                            <p class="fynn-hf-hint">
                                Keeps the rows whose <span x-text="label"></span> contains this text.
                            </p>
                        </div>
                    </template>
                </div>

                <div class="fynn-hf-footer" x-show="hasFilter">
                    <button type="button" x-on:click="close(true)" class="fynn-hf-btn">
                        Cancel
                    </button>

                    <button
                        type="button"
                        x-on:click="apply()"
                        x-bind:disabled="! canApply"
                        class="fynn-hf-btn fynn-hf-btn-primary"
                    >
                        OK
                    </button>
                </div>
            </div>
        </template>
    </div>
</div>
