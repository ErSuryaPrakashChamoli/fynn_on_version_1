{{--
    Duplicates the table's own "records per page" select (normally only
    reachable at the bottom, after scrolling through every row) at the top
    of the toolbar too. `wire:model.live="tableRecordsPerPage"` targets the
    same public property Filament's own bottom selector binds to — see
    CanPaginateRecords — so the two stay in sync automatically without any
    extra wiring.

    Options mirror Filament's table pagination default ([5, 10, 25, 50] —
    see Table::getPaginationPageOptions()). No resource in this app
    currently overrides that list; if one starts to, its top selector
    would need the same override.
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
                @foreach ([5, 10, 25, 50] as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </label>
</div>
