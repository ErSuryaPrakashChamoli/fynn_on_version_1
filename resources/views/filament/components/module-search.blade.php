{{--
    Topbar module search (GLOBAL_SEARCH_BEFORE). The button opens a pop-up
    pop-up filling the page area (right of the sidebar, below the topbar and
    marquee: the .fi-main-ctn box, re-measured while open because the sidebar
    widens on hover) and showing every module of the user's sidebar as a
    card with its sub-modules (App\Support\ModuleSearchIndex), all at once;
    typing filters it on the client. A
    module name matches all of its sub-modules. Ctrl/⌘+K or "/" opens it,
    ↑/↓ move, Enter opens, Esc closes. Styles are inline so the pop-up needs
    no theme rebuild.
--}}
@php
    $entries = \App\Support\ModuleSearchIndex::entries();
@endphp

@if ($entries !== [])
<div
    class="fynn-module-search"
    x-data="{
        open: false,
        frame: '',
        frameObserver: null,
        query: '',
        active: 0,
        entries: @js($entries),
        normalise(text) {
            return (text ?? '').toLowerCase().replace(/\s+/g, ' ').trim()
        },
        get results() {
            const terms = this.normalise(this.query).split(' ').filter(Boolean)

            if (terms.length === 0) {
                return this.entries
            }

            return this.entries
                .map((entry, index) => {
                    const label = this.normalise(entry.label)
                    const haystack = label + ' ' + this.normalise(entry.module)

                    if (! terms.every((term) => haystack.includes(term))) {
                        return null
                    }

                    // Sub-module name hits first, then module-name hits;
                    // sidebar order breaks ties.
                    const score = (label.startsWith(terms[0]) ? 0 : label.includes(terms[0]) ? 1 : 2)

                    return { entry, score, index }
                })
                .filter(Boolean)
                .sort((a, b) => a.score - b.score || a.index - b.index)
                .map((result) => result.entry)
        },
        get groups() {
            const groups = []

            this.results.forEach((entry, position) => {
                let group = groups.find((candidate) => candidate.module === entry.module)

                if (! group) {
                    group = { module: entry.module, icon: entry.moduleIcon, items: [] }
                    groups.push(group)
                }

                group.items.push({ ...entry, position })
            })

            return groups
        },
        escape(value) {
            const element = document.createElement('span')
            element.textContent = value

            return element.innerHTML
        },
        highlight(text) {
            const terms = this.normalise(this.query).split(' ').filter(Boolean)

            if (terms.length === 0) {
                return this.escape(text)
            }

            // A capturing split puts every match at an odd index.
            const pattern = new RegExp('(' + terms.map((term) => term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|') + ')', 'i')

            return text
                .split(pattern)
                .map((part, index) => index % 2 === 1 ? '<mark>' + this.escape(part) + '</mark>' : this.escape(part))
                .join('')
        },
        place() {
            const area = document.querySelector('.fi-main-ctn')

            if (! area) {
                this.frame = 'inset: 0'

                return
            }

            const box = area.getBoundingClientRect()
            const top = Math.max(0, box.top)
            const left = Math.max(0, box.left)

            this.frame = `top: ${top}px; left: ${left}px; width: ${Math.min(box.right, window.innerWidth) - left}px; height: ${Math.min(box.bottom, window.innerHeight) - top}px`
        },
        show() {
            this.place()
            this.open = true
            document.documentElement.classList.add('fynn-module-search-open')
            this.query = ''
            this.active = 0

            const area = document.querySelector('.fi-main-ctn')

            if (area && window.ResizeObserver && ! this.frameObserver) {
                this.frameObserver = new ResizeObserver(() => this.place())
                this.frameObserver.observe(area)
            }

            this.$nextTick(() => this.$refs.input?.focus())
        },
        close() {
            this.open = false
            document.documentElement.classList.remove('fynn-module-search-open')
            this.frameObserver?.disconnect()
            this.frameObserver = null
        },
        move(step) {
            const count = this.results.length

            if (count === 0) {
                return
            }

            this.active = (this.active + step + count) % count
            this.$nextTick(() => this.$refs.list?.querySelector('[data-active=true]')?.scrollIntoView({ block: 'nearest' }))
        },
        go(entry) {
            if (! entry) {
                return
            }

            this.close()

            if (entry.newTab) {
                window.open(entry.url, '_blank')

                return
            }

            window.location.href = entry.url
        },
    }"
    x-on:keydown.window="
        const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes($event.target.tagName) || $event.target.isContentEditable

        if ((($event.ctrlKey || $event.metaKey) && $event.key.toLowerCase() === 'k') || ($event.key === '/' && ! typing && ! open)) {
            $event.preventDefault()
            show()
        }
    "
    x-init="$watch('query', () => active = 0)"
>
    <button
        type="button"
        class="fynn-module-search-trigger"
        x-on:click="show()"
        title="Search modules (Ctrl+K)"
        aria-label="Search modules"
    >
        {{ \Filament\Support\generate_icon_html(\Filament\Support\Icons\Heroicon::OutlinedMagnifyingGlass) }}
        <span class="fynn-module-search-trigger-label">Search modules…</span>
        <kbd class="fynn-module-search-kbd">Ctrl K</kbd>
    </button>

    <template x-teleport="body">
        <div
            x-show="open"
            x-cloak
            x-transition.opacity.duration.150ms
            class="fynn-module-search-overlay"
            :style="frame"
            x-on:resize.window="open && place()"
            x-on:keydown.escape.prevent.stop="close()"
            x-on:click.self="close()"
        >
            <div
                class="fynn-module-search-dialog"
                role="dialog"
                aria-modal="true"
                aria-label="Search modules"
                x-trap="open"
            >
                <div class="fynn-module-search-field">
                    {{ \Filament\Support\generate_icon_html(\Filament\Support\Icons\Heroicon::OutlinedMagnifyingGlass) }}
                    <input
                        x-ref="input"
                        x-model="query"
                        type="text"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="Search all modules and sub-modules…"
                        aria-label="Search modules"
                        x-on:keydown.arrow-down.prevent="move(1)"
                        x-on:keydown.arrow-up.prevent="move(-1)"
                        x-on:keydown.enter.prevent="go(results[active])"
                    />
                    <button type="button" class="fynn-module-search-close" x-on:click="close()" aria-label="Close (Esc)" title="Close (Esc)">
                        {{ \Filament\Support\generate_icon_html(\Filament\Support\Icons\Heroicon::OutlinedXMark) }}
                    </button>
                </div>

                <div class="fynn-module-search-results" x-ref="list">
                    <div class="fynn-module-search-columns">
                    <template x-for="group in groups" :key="group.module">
                        <section class="fynn-module-search-group">
                            <h3 class="fynn-module-search-group-heading">
                                <span class="fynn-module-search-icon" x-html="group.icon"></span>
                                <span x-html="highlight(group.module)"></span>
                            </h3>

                            <template x-for="item in group.items" :key="item.url">
                                <a
                                    :href="item.url"
                                    :target="item.newTab ? '_blank' : null"
                                    class="fynn-module-search-item"
                                    :data-active="item.position === active"
                                    x-on:mouseenter="active = item.position"
                                    x-on:click.prevent="go(item)"
                                >
                                    <span class="fynn-module-search-icon" x-html="item.icon"></span>
                                    <span class="fynn-module-search-item-label" x-html="highlight(item.label)"></span>
                                    <span class="fynn-module-search-item-hint">Open ↵</span>
                                </a>
                            </template>
                        </section>
                    </template>
                    </div>

                    <p class="fynn-module-search-empty" x-show="results.length === 0">
                        No module or sub-module matches “<span x-text="query"></span>”.
                    </p>
                </div>

                <div class="fynn-module-search-footer">
                    <span><kbd>↑</kbd> <kbd>↓</kbd> move</span>
                    <span><kbd>Enter</kbd> open</span>
                    <span><kbd>Esc</kbd> close</span>
                    <span class="fynn-module-search-count" x-text="results.length + ' of ' + entries.length"></span>
                </div>
            </div>
        </div>
    </template>
</div>

<style>
    .fynn-module-search { margin-inline-end: 0.75rem; }
    .fynn-module-search-trigger {
        display: inline-flex; align-items: center; gap: 0.5rem;
        height: 2.25rem; padding: 0 0.625rem; border-radius: 0.5rem;
        font-size: 0.875rem; color: rgb(107 114 128);
        background: rgb(255 255 255); box-shadow: 0 0 0 1px rgb(3 7 18 / 0.1);
        cursor: pointer; white-space: nowrap;
    }
    .fynn-module-search-trigger:hover { box-shadow: 0 0 0 1px rgb(3 7 18 / 0.25); }
    .fynn-module-search-trigger svg, .fynn-module-search-field svg { width: 1.125rem; height: 1.125rem; flex-shrink: 0; }
    .fynn-module-search-trigger-label { min-width: 8rem; text-align: start; }
    .fynn-module-search-kbd, .fynn-module-search-footer kbd {
        font-family: inherit; font-size: 0.6875rem; line-height: 1; padding: 0.2rem 0.35rem;
        border-radius: 0.3rem; background: rgb(243 244 246); color: rgb(75 85 99);
        box-shadow: inset 0 -1px 0 rgb(3 7 18 / 0.12);
    }
    @media (max-width: 1023px) {
        .fynn-module-search-trigger-label, .fynn-module-search-kbd { display: none; }
    }

    /* Sized and placed by place(): over the page area only, so the topbar,
       marquee and sidebar stay visible and usable around it. */
    /* Above the page's sticky headers (25) and the sidebar (20), below the
       topbar (30) so its user-menu / notification dropdowns still open over it. */
    .fynn-module-search-overlay { position: fixed; z-index: 29; display: flex; }
    .fynn-module-search-open .fynn-table-scroll-to-top-ctn { display: none; }
    .fynn-module-search-dialog {
        width: 100%; height: 100%; display: flex; flex-direction: column;
        background: rgb(249 250 251); color: rgb(3 7 18); overflow: hidden;
        box-shadow: inset 1px 1px 0 rgb(3 7 18 / 0.08);
    }
    .fynn-module-search-field {
        display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1.5rem;
        background: rgb(255 255 255); border-bottom: 1px solid rgb(229 231 235); color: rgb(107 114 128);
        box-shadow: 0 1px 2px rgb(0 0 0 / 0.04);
    }
    .fynn-module-search-field > svg { width: 1.375rem; height: 1.375rem; }
    .fynn-module-search-field input {
        flex: 1; min-width: 0; border: 0; outline: 0; box-shadow: none; background: transparent;
        font-size: 1.125rem; color: inherit; padding: 0.25rem 0;
    }
    .fynn-module-search-field input:focus { box-shadow: none; }
    .fynn-module-search-dialog .fynn-module-search-field input { color: rgb(3 7 18); }
    .fynn-module-search-close {
        display: inline-flex; align-items: center; justify-content: center; width: 2.25rem; height: 2.25rem;
        border-radius: 0.5rem; color: rgb(75 85 99); cursor: pointer;
    }
    .fynn-module-search-close:hover { background: rgb(243 244 246); }
    .fynn-module-search-close svg { width: 1.375rem; height: 1.375rem; }

    /* Every module as a card; CSS columns pack cards of different heights
       tightly and keep the DOM (and ↑/↓) order running down each column. */
    .fynn-module-search-results {
        flex: 1; overflow-y: auto; overflow-x: hidden; overscroll-behavior: contain; padding: 1rem 1.5rem;
    }
    /* The columns live on an inner box with no fixed height: on the
       scrolling box itself they would overflow sideways instead of down. */
    .fynn-module-search-columns { columns: 12.5rem; column-gap: 0.75rem; }
    .fynn-module-search-group {
        break-inside: avoid; margin-bottom: 0.75rem; padding: 0.375rem;
        border-radius: 0.75rem; background: rgb(255 255 255); box-shadow: 0 0 0 1px rgb(3 7 18 / 0.06), 0 1px 2px rgb(0 0 0 / 0.04);
    }
    .fynn-module-search-group-heading {
        display: flex; align-items: center; gap: 0.5rem; margin: 0; padding: 0.375rem 0.5rem 0.5rem;
        margin-bottom: 0.125rem; border-bottom: 1px solid rgb(243 244 246);
        font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: rgb(55 65 81);
    }
    .fynn-module-search-icon { display: inline-flex; flex-shrink: 0; }
    .fynn-module-search-icon svg { width: 1.125rem; height: 1.125rem; }
    .fynn-module-search-group-heading .fynn-module-search-icon { color: var(--primary-600, rgb(79 70 229)); }
    .fynn-module-search-item {
        display: flex; align-items: center; gap: 0.625rem; padding: 0.35rem 0.5rem;
        border-radius: 0.5rem; font-size: 0.8125rem; line-height: 1.125rem; color: rgb(31 41 55); text-decoration: none;
    }
    .fynn-module-search-item .fynn-module-search-icon { color: rgb(156 163 175); }
    .fynn-module-search-item-label { flex: 1; min-width: 0; }
    .fynn-module-search-item-hint { font-size: 0.75rem; color: rgb(156 163 175); visibility: hidden; }
    .fynn-module-search-item[data-active="true"] { background: color-mix(in srgb, var(--primary-500, #6366f1) 12%, transparent); color: var(--primary-700, rgb(67 56 202)); }
    .fynn-module-search-item[data-active="true"] .fynn-module-search-icon { color: var(--primary-600, rgb(79 70 229)); }
    .fynn-module-search-item[data-active="true"] .fynn-module-search-item-hint { visibility: visible; }
    .fynn-module-search-dialog mark { background: color-mix(in srgb, var(--primary-400, #818cf8) 30%, transparent); color: inherit; border-radius: 0.2rem; padding: 0 0.05rem; }
    .fynn-module-search-empty { padding: 3rem 1rem; text-align: center; font-size: 0.875rem; color: rgb(107 114 128); }
    .fynn-module-search-footer {
        display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; padding: 0.625rem 1.5rem;
        background: rgb(255 255 255); border-top: 1px solid rgb(229 231 235); font-size: 0.75rem; color: rgb(107 114 128);
    }
    .fynn-module-search-count { margin-inline-start: auto; }

    .dark .fynn-module-search-trigger { background: rgb(255 255 255 / 0.05); color: rgb(156 163 175); box-shadow: 0 0 0 1px rgb(255 255 255 / 0.2); }
    .dark .fynn-module-search-kbd, .dark .fynn-module-search-footer kbd { background: rgb(255 255 255 / 0.1); color: rgb(209 213 219); }
    .dark .fynn-module-search-dialog { background: rgb(3 7 18); color: rgb(243 244 246); box-shadow: inset 1px 1px 0 rgb(255 255 255 / 0.08); }
    .dark .fynn-module-search-field, .dark .fynn-module-search-footer { background: rgb(17 24 39); border-color: rgb(255 255 255 / 0.1); }
    .dark .fynn-module-search-dialog .fynn-module-search-field input { color: rgb(243 244 246); }
    .dark .fynn-module-search-close { color: rgb(209 213 219); }
    .dark .fynn-module-search-close:hover { background: rgb(255 255 255 / 0.1); }
    .dark .fynn-module-search-group { background: rgb(17 24 39); box-shadow: 0 0 0 1px rgb(255 255 255 / 0.08); }
    .dark .fynn-module-search-group-heading { color: rgb(229 231 235); border-color: rgb(255 255 255 / 0.08); }
    .dark .fynn-module-search-group-heading .fynn-module-search-icon { color: var(--primary-400, rgb(129 140 248)); }
    .dark .fynn-module-search-item { color: rgb(229 231 235); }
    .dark .fynn-module-search-item[data-active="true"] { color: var(--primary-300, rgb(165 180 252)); }
</style>
@endif
