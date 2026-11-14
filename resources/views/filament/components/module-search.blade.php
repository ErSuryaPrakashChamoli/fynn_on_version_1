{{--
    Topbar module search (GLOBAL_SEARCH_BEFORE). The button opens a pop-up
    filling the page area (right of the sidebar, below the topbar and
    marquee: the .fi-main-ctn box, re-measured while open because the sidebar
    widens on hover) and showing every module of the user's sidebar as a
    card with its sub-modules (App\Support\ModuleSearchIndex), all at once;
    typing filters it on the client. A module name matches all of its
    sub-modules. Ctrl/⌘+K or "/" opens it, ↑/↓ move, Enter opens, Esc
    closes. Each module card gets its own tone (--tone) from a fixed palette
    in sidebar order. Styles are inline so the pop-up needs no theme rebuild.
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
        tones: ['#059669', '#7c3aed', '#2563eb', '#d97706', '#e11d48', '#0891b2', '#4f46e5', '#ea580c', '#475569', '#0d9488', '#c026d3', '#4d7c0f'],
        modules: [],
        init() {
            this.modules = [...new Set(this.entries.map((entry) => entry.module))]
        },
        toneFor(module) {
            return this.tones[Math.max(0, this.modules.indexOf(module)) % this.tones.length]
        },
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
                    group = { module: entry.module, icon: entry.moduleIcon, tone: this.toneFor(entry.module), items: [] }
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
                    <div class="fynn-module-search-input" x-on:click.self="$refs.input.focus()">
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
                        <button type="button" class="fynn-module-search-clear" x-show="query !== ''" x-on:click="query = ''; $refs.input.focus()" aria-label="Clear search" title="Clear search">
                            {{ \Filament\Support\generate_icon_html(\Filament\Support\Icons\Heroicon::OutlinedXCircle) }}
                        </button>
                    </div>
                    <span class="fynn-module-search-summary" x-text="modules.length + ' modules · ' + entries.length + ' screens'"></span>
                    <button type="button" class="fynn-module-search-close" x-on:click="close()" aria-label="Close (Esc)" title="Close (Esc)">
                        {{ \Filament\Support\generate_icon_html(\Filament\Support\Icons\Heroicon::OutlinedXMark) }}
                    </button>
                </div>

                <div class="fynn-module-search-results" x-ref="list">
                    <div class="fynn-module-search-columns">
                        <template x-for="group in groups" :key="group.module">
                            <section class="fynn-module-search-group" :style="'--tone: ' + group.tone">
                                <h3 class="fynn-module-search-group-heading">
                                    <span class="fynn-module-search-icon" x-html="group.icon"></span>
                                    <span class="fynn-module-search-group-title" x-html="highlight(group.module)"></span>
                                    <span class="fynn-module-search-group-count" x-text="group.items.length"></span>
                                </h3>

                                <div class="fynn-module-search-group-items">
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
                                            <span class="fynn-module-search-item-go" aria-hidden="true">
                                                {{ \Filament\Support\generate_icon_html(\Filament\Support\Icons\Heroicon::OutlinedArrowRight) }}
                                            </span>
                                        </a>
                                    </template>
                                </div>
                            </section>
                        </template>
                    </div>

                    <div class="fynn-module-search-empty" x-show="results.length === 0">
                        <span class="fynn-module-search-empty-icon">
                            {{ \Filament\Support\generate_icon_html(\Filament\Support\Icons\Heroicon::OutlinedMagnifyingGlass) }}
                        </span>
                        <p class="fynn-module-search-empty-title">No matches</p>
                        <p>No module or sub-module matches “<span x-text="query"></span>”.</p>
                    </div>
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
        height: 2.25rem; padding: 0 0.625rem; border-radius: var(--fynn-radius-md, 0.625rem);
        font-size: 0.875rem; color: var(--fynn-text-3, rgb(107 114 128));
        background: var(--fynn-surface, rgb(255 255 255));
        box-shadow: var(--fynn-shadow-xs, 0 1px 2px rgb(16 24 40 / 0.05)), 0 0 0 1px var(--fynn-border-strong, rgb(3 7 18 / 0.1));
        cursor: pointer; white-space: nowrap; transition: box-shadow 150ms ease, color 150ms ease;
    }
    .fynn-module-search-trigger:hover { color: var(--fynn-text-2, rgb(55 65 81)); box-shadow: var(--fynn-shadow-xs, 0 1px 2px rgb(16 24 40 / 0.05)), 0 0 0 1px color-mix(in oklab, var(--gray-950, #030712) 26%, transparent); }
    .fynn-module-search-trigger:focus-visible { outline: 0; box-shadow: 0 0 0 1px var(--primary-600, rgb(79 70 229)), 0 0 0 4px color-mix(in oklab, var(--primary-500, #6366f1) 22%, transparent); }
    .fynn-module-search-trigger svg { width: 1.125rem; height: 1.125rem; flex-shrink: 0; }
    .fynn-module-search-trigger-label { min-width: 8rem; text-align: start; }
    .fynn-module-search-kbd, .fynn-module-search-footer kbd {
        font-family: inherit; font-size: 0.6875rem; font-weight: 600; line-height: 1; padding: 0.2rem 0.35rem;
        border-radius: 0.3rem; background: var(--fynn-surface-3, rgb(243 244 246)); color: var(--fynn-text-2, rgb(75 85 99));
        box-shadow: inset 0 0 0 1px var(--fynn-border, rgb(3 7 18 / 0.08)), inset 0 -1px 0 rgb(3 7 18 / 0.12);
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
        width: 100%; height: 100%; display: flex; flex-direction: column; overflow: hidden;
        color: var(--fynn-text, rgb(3 7 18));
        background:
            radial-gradient(60rem 22rem at 0% 0%, color-mix(in oklab, var(--primary-500, #6366f1) 8%, transparent), transparent 70%),
            radial-gradient(50rem 20rem at 100% 100%, color-mix(in oklab, var(--primary-500, #6366f1) 5%, transparent), transparent 70%),
            var(--fynn-page, rgb(249 250 251));
        box-shadow: inset 1px 1px 0 var(--fynn-border, rgb(3 7 18 / 0.08));
    }

    /* Search bar: a real input box with a focus ring, a module count and close. */
    .fynn-module-search-field {
        display: flex; align-items: center; gap: 0.875rem; padding: 0.875rem 1.5rem;
        background: color-mix(in oklab, var(--fynn-surface, rgb(255 255 255)) 88%, transparent);
        backdrop-filter: blur(8px);
        border-bottom: 1px solid var(--fynn-border, rgb(229 231 235));
    }
    .fynn-module-search-input {
        flex: 1; min-width: 0; display: flex; align-items: center; gap: 0.625rem; height: 2.875rem; padding: 0 0.5rem 0 0.875rem;
        border-radius: var(--fynn-radius-md, 0.625rem); background: var(--fynn-surface-2, rgb(249 250 251));
        box-shadow: inset 0 0 0 1px var(--fynn-border, rgb(3 7 18 / 0.09));
        color: var(--fynn-text-3, rgb(107 114 128)); cursor: text;
        transition: box-shadow 150ms ease, background-color 150ms ease;
    }
    .fynn-module-search-input:focus-within {
        background: var(--fynn-surface, rgb(255 255 255)); color: var(--primary-600, rgb(79 70 229));
        box-shadow: inset 0 0 0 1px var(--primary-500, #6366f1), 0 0 0 4px color-mix(in oklab, var(--primary-500, #6366f1) 16%, transparent);
    }
    .fynn-module-search-input > svg { width: 1.25rem; height: 1.25rem; flex-shrink: 0; }
    .fynn-module-search-dialog .fynn-module-search-input input {
        flex: 1; min-width: 0; height: 100%; border: 0; outline: 0; box-shadow: none; background: transparent;
        font-size: 1rem; color: var(--fynn-text, rgb(3 7 18)); padding: 0;
    }
    .fynn-module-search-dialog .fynn-module-search-input input::placeholder { color: var(--fynn-text-3, rgb(107 114 128)); }
    .fynn-module-search-dialog .fynn-module-search-input input:focus { box-shadow: none; }
    .fynn-module-search-clear {
        display: inline-flex; align-items: center; justify-content: center; width: 1.875rem; height: 1.875rem; flex-shrink: 0;
        border-radius: 999px; color: var(--fynn-text-3, rgb(107 114 128)); cursor: pointer;
    }
    .fynn-module-search-clear:hover { color: var(--fynn-text, rgb(3 7 18)); background: var(--fynn-surface-3, rgb(243 244 246)); }
    .fynn-module-search-clear svg { width: 1.125rem; height: 1.125rem; }
    .fynn-module-search-summary {
        flex-shrink: 0; padding: 0.3125rem 0.75rem; border-radius: 999px; font-size: 0.75rem; font-weight: 600;
        color: var(--fynn-accent-text, var(--primary-700, rgb(67 56 202))); background: var(--fynn-accent-soft, rgb(238 242 255));
        font-variant-numeric: tabular-nums; white-space: nowrap;
    }
    .fynn-module-search-close {
        display: inline-flex; align-items: center; justify-content: center; width: 2.5rem; height: 2.5rem; flex-shrink: 0;
        border-radius: var(--fynn-radius-md, 0.625rem); color: var(--fynn-text-2, rgb(75 85 99)); cursor: pointer;
        background: var(--fynn-surface, rgb(255 255 255)); box-shadow: inset 0 0 0 1px var(--fynn-border, rgb(3 7 18 / 0.09));
        transition: background-color 150ms ease, color 150ms ease;
    }
    .fynn-module-search-close:hover { color: var(--fynn-text, rgb(3 7 18)); background: var(--fynn-surface-3, rgb(243 244 246)); }
    .fynn-module-search-close svg { width: 1.25rem; height: 1.25rem; }
    @media (max-width: 767px) {
        .fynn-module-search-summary { display: none; }
        .fynn-module-search-field { padding-inline: 1rem; }
    }

    /* Every module as a card; CSS columns pack cards of different heights
       tightly and keep the DOM (and ↑/↓) order running down each column. */
    .fynn-module-search-results {
        flex: 1; overflow-y: auto; overflow-x: hidden; overscroll-behavior: contain; padding: 1.25rem 1.5rem 1.5rem;
    }
    /* The columns live on an inner box with no fixed height: on the
       scrolling box itself they would overflow sideways instead of down. */
    .fynn-module-search-columns { columns: 15rem; column-gap: 1rem; }

    .fynn-module-search-group {
        --tone: var(--primary-500, #6366f1);
        position: relative; break-inside: avoid; margin-bottom: 1rem; padding: 0 0.375rem 0.375rem; overflow: hidden;
        border-radius: var(--fynn-radius-lg, 0.875rem); background: var(--fynn-surface, rgb(255 255 255));
        box-shadow: var(--fynn-shadow-sm, 0 1px 2px rgb(0 0 0 / 0.04)), 0 0 0 1px var(--fynn-border-soft, rgb(3 7 18 / 0.06));
        transition: box-shadow 180ms ease;
    }
    .fynn-module-search-group::before {
        content: ''; position: absolute; inset: 0 0 auto; height: 3px;
        background: linear-gradient(90deg, var(--tone), color-mix(in oklab, var(--tone) 35%, transparent));
    }
    .fynn-module-search-group:hover,
    .fynn-module-search-group:has([data-active="true"]) {
        box-shadow: var(--fynn-shadow-md, 0 12px 28px -10px rgb(16 24 40 / 0.16)), 0 0 0 1px color-mix(in oklab, var(--tone) 30%, transparent);
    }

    .fynn-module-search-group-heading {
        display: flex; align-items: center; gap: 0.625rem; margin: 0 -0.375rem 0.375rem; padding: 0.875rem 0.875rem 0.75rem;
        background: linear-gradient(180deg, color-mix(in oklab, var(--tone) 9%, transparent), color-mix(in oklab, var(--tone) 2%, transparent));
        border-bottom: 1px solid color-mix(in oklab, var(--tone) 12%, transparent);
    }
    .fynn-module-search-group-title {
        flex: 1; min-width: 0; font-size: 0.875rem; font-weight: 700; line-height: 1.25rem; letter-spacing: -0.005em;
        color: var(--fynn-text, rgb(3 7 18));
    }
    .fynn-module-search-group-count {
        flex-shrink: 0; min-width: 1.5rem; padding: 0.125rem 0.4375rem; border-radius: 999px; text-align: center;
        font-size: 0.6875rem; font-weight: 700; line-height: 1rem; font-variant-numeric: tabular-nums;
        color: color-mix(in oklab, var(--tone) 72%, black); background: color-mix(in oklab, var(--tone) 13%, transparent);
    }

    .fynn-module-search-icon { display: inline-flex; flex-shrink: 0; align-items: center; justify-content: center; }
    .fynn-module-search-icon svg { width: 1rem; height: 1rem; }
    .fynn-module-search-group-heading .fynn-module-search-icon {
        width: 2.125rem; height: 2.125rem; border-radius: 0.625rem; color: rgb(255 255 255);
        background: linear-gradient(140deg, color-mix(in oklab, var(--tone) 78%, white), var(--tone) 70%);
        box-shadow: 0 6px 14px -6px color-mix(in oklab, var(--tone) 80%, transparent), inset 0 1px 0 rgb(255 255 255 / 0.28);
    }
    .fynn-module-search-group-heading .fynn-module-search-icon svg { width: 1.125rem; height: 1.125rem; }

    .fynn-module-search-group-items { display: flex; flex-direction: column; gap: 1px; }
    .fynn-module-search-item {
        position: relative; display: flex; align-items: center; gap: 0.625rem; padding: 0.375rem 0.5rem;
        border-radius: var(--fynn-radius-sm, 0.5rem); font-size: 0.8125rem; font-weight: 500; line-height: 1.125rem;
        color: var(--fynn-text-2, rgb(55 65 81)); text-decoration: none;
        transition: background-color 120ms ease, color 120ms ease;
    }
    .fynn-module-search-item .fynn-module-search-icon {
        width: 1.75rem; height: 1.75rem; border-radius: 0.4375rem;
        color: var(--fynn-text-3, rgb(107 114 128)); background: var(--fynn-surface-2, rgb(249 250 251));
        box-shadow: inset 0 0 0 1px var(--fynn-border-soft, rgb(3 7 18 / 0.06));
        transition: background-color 120ms ease, color 120ms ease, box-shadow 120ms ease;
    }
    .fynn-module-search-item-label { flex: 1; min-width: 0; }
    .fynn-module-search-item-go {
        display: inline-flex; flex-shrink: 0; color: var(--tone); opacity: 0; transform: translateX(-4px);
        transition: opacity 120ms ease, transform 120ms ease;
    }
    .fynn-module-search-item-go svg { width: 0.875rem; height: 0.875rem; }

    .fynn-module-search-item[data-active="true"] {
        background: color-mix(in oklab, var(--tone) 10%, transparent);
        color: color-mix(in oklab, var(--tone) 72%, black); font-weight: 600;
    }
    .fynn-module-search-item[data-active="true"]::before {
        content: ''; position: absolute; inset-block: 0.4375rem; inset-inline-start: -0.375rem; width: 3px;
        border-radius: 0 3px 3px 0; background: var(--tone);
    }
    .fynn-module-search-item[data-active="true"] .fynn-module-search-icon {
        color: rgb(255 255 255); background: var(--tone);
        box-shadow: 0 4px 10px -4px color-mix(in oklab, var(--tone) 80%, transparent);
    }
    .fynn-module-search-item[data-active="true"] .fynn-module-search-item-go { opacity: 1; transform: none; }

    .fynn-module-search-dialog mark {
        background: color-mix(in oklab, var(--tone, var(--primary-500, #6366f1)) 22%, transparent); color: inherit;
        border-radius: 0.25rem; padding: 0 0.0625rem; box-shadow: inset 0 -2px 0 color-mix(in oklab, var(--tone, var(--primary-500, #6366f1)) 55%, transparent);
    }

    .fynn-module-search-empty {
        display: flex; flex-direction: column; align-items: center; gap: 0.375rem; padding: 4rem 1rem;
        text-align: center; font-size: 0.875rem; color: var(--fynn-text-3, rgb(107 114 128));
    }
    .fynn-module-search-empty p { margin: 0; }
    .fynn-module-search-empty-icon {
        display: inline-flex; align-items: center; justify-content: center; width: 3rem; height: 3rem; margin-bottom: 0.5rem;
        border-radius: 999px; color: var(--fynn-accent-text, var(--primary-700, rgb(67 56 202))); background: var(--fynn-accent-soft, rgb(238 242 255));
    }
    .fynn-module-search-empty-icon svg { width: 1.5rem; height: 1.5rem; }
    .fynn-module-search-empty-title { font-size: 0.9375rem; font-weight: 700; color: var(--fynn-text, rgb(3 7 18)); }

    .fynn-module-search-footer {
        display: flex; flex-wrap: wrap; gap: 1.25rem; align-items: center; padding: 0.625rem 1.5rem;
        background: var(--fynn-surface, rgb(255 255 255)); border-top: 1px solid var(--fynn-border, rgb(229 231 235));
        font-size: 0.75rem; color: var(--fynn-text-3, rgb(107 114 128));
    }
    .fynn-module-search-count { margin-inline-start: auto; font-weight: 600; font-variant-numeric: tabular-nums; }

    @media (prefers-reduced-motion: reduce) {
        .fynn-module-search-trigger, .fynn-module-search-input, .fynn-module-search-close, .fynn-module-search-group,
        .fynn-module-search-item, .fynn-module-search-item .fynn-module-search-icon, .fynn-module-search-item-go { transition: none; }
    }

    .dark .fynn-module-search-trigger { background: rgb(255 255 255 / 0.05); color: rgb(156 163 175); box-shadow: 0 0 0 1px rgb(255 255 255 / 0.2); }
    .dark .fynn-module-search-trigger:hover { color: rgb(209 213 219); box-shadow: 0 0 0 1px rgb(255 255 255 / 0.28); }
    .dark .fynn-module-search-kbd, .dark .fynn-module-search-footer kbd { background: rgb(255 255 255 / 0.1); color: rgb(209 213 219); }
    .dark .fynn-module-search-dialog {
        background:
            radial-gradient(60rem 22rem at 0% 0%, color-mix(in oklab, var(--primary-400, #818cf8) 10%, transparent), transparent 70%),
            var(--fynn-page, rgb(3 7 18));
        color: rgb(243 244 246);
    }
    .dark .fynn-module-search-field { background: color-mix(in oklab, var(--fynn-surface, rgb(17 24 39)) 88%, transparent); }
    .dark .fynn-module-search-input:focus-within { color: var(--primary-400, #818cf8); }
    .dark .fynn-module-search-group-count { color: color-mix(in oklab, var(--tone) 55%, white); background: color-mix(in oklab, var(--tone) 22%, transparent); }
    .dark .fynn-module-search-group-heading { background: linear-gradient(180deg, color-mix(in oklab, var(--tone) 16%, transparent), color-mix(in oklab, var(--tone) 3%, transparent)); }
    .dark .fynn-module-search-item[data-active="true"] { color: color-mix(in oklab, var(--tone) 50%, white); background: color-mix(in oklab, var(--tone) 18%, transparent); }
    .dark .fynn-module-search-item-go { color: color-mix(in oklab, var(--tone) 55%, white); }
</style>
@endif
