{{--
    Panel-wide period picker: a calendar month, a financial year (April to
    March) or all time. Follows the exact cookie-write + full-reload pattern
    used by the theme switcher (resources/views/vendor/filament-panels/components/theme-switcher/index.blade.php):
    plain client-side JS sets unencrypted cookies and reloads, read back
    server-side by App\Support\SelectedMonth on every request. No Livewire
    round-trip needed since every table/widget already re-queries fresh on
    a full page load.

    Cookies: `selected_month` ("Y-m") and `selected_period` ("month",
    "fy:2026" or "all"), both listed in App\Http\Middleware\EncryptCookies.
--}}
@php
    $selected = \App\Support\SelectedMonth::selectedMonth();
    $mode = \App\Support\SelectedMonth::mode();
    $financialYearStart = \App\Support\SelectedMonth::financialYearStart();
    $currentFinancialYearStart = \App\Support\SelectedMonth::financialYearStartFor(now());
    $selectClass = 'fi-input fi-select-input block w-full rounded-lg border-none bg-white py-1.5 text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/20';
@endphp
<div
    x-data="{
        setCookie(name, value) {
            document.cookie = name + '=' + value + '; path=/; max-age=31536000; samesite=lax'
        },
        pick(period, month) {
            if (month) { this.setCookie('selected_month', month) }
            this.setCookie('selected_period', period)
            window.location.reload()
        },
    }"
    role="group"
    aria-label="Select period"
    class="fi-global-month-selector"
    style="display: flex; align-items: center; gap: 0.375rem; margin-inline-end: 0.75rem;"
>
    <select
        aria-label="Period"
        title="Show data for a month, a financial year, or all time"
        x-on:change="
            const value = $event.target.value
            pick(value === 'fy' ? 'fy:{{ $currentFinancialYearStart }}' : value)
        "
        class="{{ $selectClass }}"
    >
        @foreach (\App\Support\SelectedMonth::modeOptions() as $value => $label)
            <option value="{{ $value }}" @selected($mode === $value)>{{ $label }}</option>
        @endforeach
    </select>

    @if ($mode === \App\Support\SelectedMonth::MODE_MONTH)
        <select
            aria-label="Month"
            x-on:change="pick('month', '{{ $selected->year }}-' + $event.target.value.padStart(2, '0'))"
            class="{{ $selectClass }}"
        >
            @foreach (\App\Support\SelectedMonth::monthOptions() as $value => $label)
                <option value="{{ $value }}" @selected($selected->month === $value)>{{ $label }}</option>
            @endforeach
        </select>

        <select
            aria-label="Year"
            x-on:change="pick('month', $event.target.value + '-{{ str_pad((string) $selected->month, 2, '0', STR_PAD_LEFT) }}')"
            class="{{ $selectClass }}"
        >
            @foreach (\App\Support\SelectedMonth::yearOptions() as $year)
                <option value="{{ $year }}" @selected($selected->year === $year)>{{ $year }}</option>
            @endforeach
        </select>
    @elseif ($mode === \App\Support\SelectedMonth::MODE_FINANCIAL_YEAR)
        <select
            aria-label="Financial year"
            x-on:change="pick('fy:' + $event.target.value)"
            class="{{ $selectClass }}"
        >
            @foreach (\App\Support\SelectedMonth::financialYearOptions() as $startYear => $label)
                <option value="{{ $startYear }}" @selected($financialYearStart === $startYear)>{{ $label }}</option>
            @endforeach
        </select>
    @endif
</div>
