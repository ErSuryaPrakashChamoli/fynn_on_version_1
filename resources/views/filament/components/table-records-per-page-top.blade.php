{{--
    Duplicates the table's own "records per page" select (normally only
    reachable at the bottom, after scrolling through every row) at the top
    of the toolbar too. `wire:model.live="tableRecordsPerPage"` targets the
    same public property Filament's own bottom selector binds to — see
    CanPaginateRecords — so the two stay in sync automatically without any
    extra wiring.

    Options come from App\Support\TablePaginationOptions, the same list
    AdminPanelProvider::configurePaginationOptions() sets as every table's
    default. A <select> whose current value has no matching <option> falls
    back to showing its first option, which is how this one used to read
    "5" while the bottom one read "All" — so the two lists must be the same.
--}}
<div
    x-data="{
        visible: false,
        check() {
            const ctn = this.$el.closest('.fi-ta-ctn')
            this.visible = !! (ctn && ctn.querySelector('.fi-pagination-records-per-page-select-ctn'))
        },
    }"
    x-init="
        check()
        observer = new MutationObserver(() => check())
        observer.observe($el.closest('.fi-ta-ctn') ?? $el, { childList: true, subtree: true })
    "
    x-show="visible"
    x-cloak
    class="fynn-table-records-per-page-top"
>
    <label class="fi-pagination-records-per-page-select">
        <x-filament::input.wrapper :prefix="__('filament::components/pagination.fields.records_per_page.label')">
            <x-filament::input.select wire:model.live="tableRecordsPerPage">
                @foreach (\App\Support\TablePaginationOptions::OPTIONS as $option)
                    <option value="{{ $option }}">
                        {{ $option === 'all' ? __('filament::components/pagination.fields.records_per_page.options.all') : $option }}
                    </option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </label>
</div>
