<div class="space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex min-w-0 items-center gap-3">
            <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-(--fynn-accent-soft) text-(--fynn-accent-text) ring-1 ring-inset ring-primary-500/20">
                <x-filament::icon icon="heroicon-o-document-text" class="size-5" />
            </span>
            <div class="min-w-0">
                <div class="truncate text-sm font-semibold text-gray-950 dark:text-white">{{ $document->original_name }}</div>
                <div class="fynn-list-meta">{{ $document->page_count ? $document->page_count . ' page(s)' : 'Page count pending' }}</div>
            </div>
        </div>
        <x-filament::button
            tag="a"
            :href="$document->file_url"
            target="_blank"
            color="gray"
            icon="heroicon-m-arrow-top-right-on-square"
        >
            Open Document
        </x-filament::button>
    </div>

    <div class="fynn-card fynn-card--flush bg-(--fynn-surface-2)">
        <iframe
            src="{{ $document->file_url }}"
            class="h-[75vh] w-full"
            title="{{ $document->original_name }}"
        ></iframe>
    </div>
</div>
