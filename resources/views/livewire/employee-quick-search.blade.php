{{--
    Topbar "Find employee" box (GLOBAL_SEARCH_BEFORE, App\Livewire\EmployeeQuickSearch).
    Typing searches by name or employee ID on the server (the user's own
    scope only); each result is a plain link to the Employee Lookup page,
    so Enter / click simply navigates. Ctrl/⌘+E focuses the box from
    anywhere (Ctrl/⌘+K and "/" belong to the module search), ↑/↓ move,
    Enter opens, Esc closes. Styles are inline so the box needs no theme
    rebuild.
--}}
<div
    class="fynn-employee-search"
    x-data="{
        open: false,
        active: -1,
        links() {
            return this.$refs.list ? Array.from(this.$refs.list.querySelectorAll('a')) : []
        },
        highlight() {
            this.links().forEach((link, index) => link.classList.toggle('is-active', index === this.active))
        },
        move(step) {
            const links = this.links()

            if (links.length === 0) {
                return
            }

            this.open = true
            this.active = (this.active + step + links.length) % links.length
            this.highlight()
            links[this.active].scrollIntoView({ block: 'nearest' })
        },
        hover(link) {
            this.active = this.links().indexOf(link)
            this.highlight()
        },
        go() {
            const links = this.links()
            const link = links[this.active] ?? links[0]

            if (link) {
                link.click()
            }
        },
        reset() {
            this.open = true
            this.active = -1
        },
        focusSearch() {
            this.$refs.input.focus()
            this.$refs.input.select()
            this.open = true
        },
    }"
    x-on:click.outside="open = false"
    x-on:keydown.escape="open = false"
    x-on:keydown.window="
        if (($event.ctrlKey || $event.metaKey) && ! $event.shiftKey && ! $event.altKey && $event.key.toLowerCase() === 'e') {
            $event.preventDefault()
            focusSearch()
        }
    "
>
    <label class="fynn-employee-search-field" title="Find an employee by name or employee ID (Ctrl+E)">
        {{ \Filament\Support\generate_icon_html(\Filament\Support\Icons\Heroicon::OutlinedUserCircle) }}
        <input
            x-ref="input"
            type="search"
            wire:model.live.debounce.250ms="search"
            x-on:focus="open = true"
            x-on:input="reset()"
            x-on:keydown.arrow-down.prevent="move(1)"
            x-on:keydown.arrow-up.prevent="move(-1)"
            x-on:keydown.enter.prevent="go()"
            placeholder="Find employee…"
            aria-label="Find employee by name or employee ID"
            autocomplete="off"
            spellcheck="false"
            aria-keyshortcuts="Control+E Meta+E"
        />
        <kbd class="fynn-employee-search-kbd">Ctrl E</kbd>
    </label>

    @if (trim($search) !== '')
        <div
            x-show="open"
            x-ref="list"
            class="fynn-employee-search-panel"
            role="listbox"
            aria-label="Matching employees"
            wire:loading.class="is-loading"
            wire:target="search"
        >
            @forelse ($this->results as $result)
                <a
                    href="{{ $result['url'] }}"
                    wire:key="employee-result-{{ $result['id'] }}"
                    role="option"
                    class="fynn-employee-search-option"
                    x-on:mouseenter="hover($el)"
                >
                    <span class="fynn-employee-search-name">
                        {{ $result['name'] }}
                        <span class="fynn-employee-search-id">({{ $result['emp_id'] ?: '—' }})</span>
                    </span>
                    <span class="fynn-employee-search-meta">
                        {{ $result['designation'] }}@if ($result['exited']) · Exited @endif
                    </span>
                </a>
            @empty
                <div class="fynn-employee-search-empty">
                    No employee in your view matches “{{ $search }}”.
                </div>
            @endforelse
        </div>
    @endif

    <style>
        .fynn-employee-search { position: relative; }
        .fynn-employee-search-field {
            display: inline-flex; align-items: center; gap: 0.5rem;
            height: 2.25rem; padding: 0 0.625rem; border-radius: var(--fynn-radius-md, 0.625rem);
            font-size: 0.875rem; color: var(--fynn-text-3, rgb(107 114 128));
            background: var(--fynn-surface, rgb(255 255 255));
            box-shadow: var(--fynn-shadow-xs, 0 1px 2px rgb(16 24 40 / 0.05)), 0 0 0 1px var(--fynn-border-strong, rgb(3 7 18 / 0.1));
            cursor: text; transition: box-shadow 150ms ease;
        }
        .fynn-employee-search-field:hover { box-shadow: var(--fynn-shadow-xs, 0 1px 2px rgb(16 24 40 / 0.05)), 0 0 0 1px color-mix(in oklab, var(--gray-950, #030712) 26%, transparent); }
        .fynn-employee-search-field:focus-within {
            color: var(--fynn-accent-text, var(--primary-600));
            box-shadow: 0 0 0 1px var(--primary-600, rgb(79 70 229)), 0 0 0 4px color-mix(in oklab, var(--primary-500, #6366f1) 22%, transparent);
        }
        .fynn-employee-search-field svg { width: 1.125rem; height: 1.125rem; flex-shrink: 0; }
        .fynn-employee-search-field input {
            width: 11rem; min-width: 0; border: 0; outline: 0; box-shadow: none; background: transparent;
            padding: 0; font-size: 0.875rem; color: var(--fynn-text, rgb(3 7 18));
        }
        .fynn-employee-search-field input::placeholder { color: var(--fynn-text-3, rgb(107 114 128)); }
        .fynn-employee-search-field input:focus { box-shadow: none; }
        .fynn-employee-search-kbd {
            font-family: inherit; font-size: 0.6875rem; font-weight: 600; line-height: 1; padding: 0.2rem 0.35rem; white-space: nowrap;
            border-radius: 0.3rem; background: var(--fynn-surface-3, rgb(243 244 246)); color: var(--fynn-text-2, rgb(75 85 99));
            box-shadow: inset 0 0 0 1px var(--fynn-border, rgb(3 7 18 / 0.08)), inset 0 -1px 0 rgb(3 7 18 / 0.12);
        }
        .fynn-employee-search-field:focus-within .fynn-employee-search-kbd { visibility: hidden; }
        .fynn-employee-search-panel {
            position: absolute; top: calc(100% + 0.5rem); right: 0; z-index: 40;
            width: 22rem; max-width: calc(100vw - 2rem); max-height: 22rem; overflow-y: auto;
            padding: 0.375rem; border-radius: var(--fynn-radius-lg, 0.875rem); background: var(--fynn-surface, rgb(255 255 255));
            box-shadow: var(--fynn-shadow-lg, 0 10px 25px rgb(0 0 0 / 0.15)), 0 0 0 1px var(--fynn-border, rgb(3 7 18 / 0.08));
        }
        .fynn-employee-search-panel.is-loading { opacity: 0.6; }
        .fynn-employee-search-option {
            position: relative; display: flex; flex-direction: column; gap: 0.125rem;
            padding: 0.5rem 0.75rem; border-radius: var(--fynn-radius-sm, 0.5rem); color: var(--fynn-text, rgb(3 7 18)); text-decoration: none;
            transition: background-color 120ms ease;
        }
        .fynn-employee-search-option.is-active, .fynn-employee-search-option:focus { background: var(--fynn-accent-soft, rgb(243 244 246)); outline: 0; }
        .fynn-employee-search-option.is-active::before {
            content: ''; position: absolute; inset-block: 0.5rem; inset-inline-start: 0; width: 3px;
            border-radius: 3px; background: var(--primary-500, rgb(99 102 241));
        }
        .fynn-employee-search-name { font-size: 0.875rem; font-weight: 600; }
        .fynn-employee-search-id { font-weight: 700; color: var(--fynn-accent-text, var(--primary-600, rgb(79 70 229))); font-variant-numeric: tabular-nums; }
        .fynn-employee-search-meta { font-size: 0.75rem; color: var(--fynn-text-3, rgb(107 114 128)); }
        .fynn-employee-search-empty { padding: 1rem 0.75rem; text-align: center; font-size: 0.875rem; color: var(--fynn-text-3, rgb(107 114 128)); }
        @media (max-width: 1023px) {
            .fynn-employee-search-field input { width: 7rem; }
            .fynn-employee-search-kbd { display: none; }
        }
        @media (prefers-reduced-motion: reduce) {
            .fynn-employee-search-field, .fynn-employee-search-option { transition: none; }
        }
        .dark .fynn-employee-search-kbd { background: rgb(255 255 255 / 0.1); color: rgb(209 213 219); box-shadow: inset 0 0 0 1px rgb(255 255 255 / 0.08); }
        .dark .fynn-employee-search-field { background: rgb(255 255 255 / 0.05); color: rgb(156 163 175); box-shadow: 0 0 0 1px rgb(255 255 255 / 0.2); }
        .dark .fynn-employee-search-field:hover { box-shadow: 0 0 0 1px rgb(255 255 255 / 0.28); }
        .dark .fynn-employee-search-field:focus-within { color: var(--primary-300, rgb(165 180 252)); box-shadow: 0 0 0 1px var(--primary-500, rgb(99 102 241)), 0 0 0 4px color-mix(in oklab, var(--primary-500, #6366f1) 22%, transparent); }
        .dark .fynn-employee-search-field input { color: rgb(243 244 246); }
        .dark .fynn-employee-search-panel { background: var(--gray-900, rgb(17 24 39)); box-shadow: 0 10px 25px rgb(0 0 0 / 0.4), 0 0 0 1px rgb(255 255 255 / 0.1); }
        .dark .fynn-employee-search-option { color: rgb(243 244 246); }
        .dark .fynn-employee-search-option.is-active, .dark .fynn-employee-search-option:focus { background: color-mix(in oklab, var(--primary-400, #818cf8) 14%, transparent); }
        .dark .fynn-employee-search-id { color: var(--primary-300, rgb(165 180 252)); }
        .dark .fynn-employee-search-meta, .dark .fynn-employee-search-empty { color: rgb(156 163 175); }
    </style>
</div>
