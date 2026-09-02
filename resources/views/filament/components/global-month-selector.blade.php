{{--
    Panel-wide month picker. Follows the exact cookie-write + full-reload
    pattern used by the theme switcher (resources/views/vendor/filament-panels/components/theme-switcher/index.blade.php):
    plain client-side JS sets an unencrypted cookie and reloads, read back
    server-side by App\Support\SelectedMonth on every request. No Livewire
    round-trip needed since every table/widget already re-queries fresh on
    a full page load.
--}}
@php
    $selected = \App\Support\SelectedMonth::current();
@endphp
<div
    x-data="{}"
    role="group"
    aria-label="Select month"
    class="fi-global-month-selector"
    style="display: flex; align-items: center; gap: 0.375rem; margin-inline-end: 0.75rem;"
>
    <select
        aria-label="Month"
        x-on:change="
            document.cookie = 'selected_month={{ $selected->year }}-' + $event.target.value.padStart(2, '0') + '; path=/; max-age=31536000; samesite=lax'
            window.location.reload()
        "
        class="fi-input fi-select-input block w-full rounded-lg border-none bg-white py-1.5 text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/20"
    >
        @foreach (\App\Support\SelectedMonth::monthOptions() as $value => $label)
            <option value="{{ $value }}" @selected($selected->month === $value)>{{ $label }}</option>
        @endforeach
    </select>

    <select
        aria-label="Year"
        x-on:change="
            document.cookie = 'selected_month=' + $event.target.value + '-{{ str_pad((string) $selected->month, 2, '0', STR_PAD_LEFT) }}; path=/; max-age=31536000; samesite=lax'
            window.location.reload()
        "
        class="fi-input fi-select-input block w-full rounded-lg border-none bg-white py-1.5 text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/20"
    >
        @foreach (\App\Support\SelectedMonth::yearOptions() as $year)
            <option value="{{ $year }}" @selected($selected->year === $year)>{{ $year }}</option>
        @endforeach
    </select>
</div>
