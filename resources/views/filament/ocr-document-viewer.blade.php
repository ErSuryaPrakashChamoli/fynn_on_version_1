<div class="space-y-3">
    <div class="flex items-center justify-between gap-3">
        <div>
            <div class="text-sm font-medium">{{ $document->original_name }}</div>
            <div class="text-xs text-gray-500">{{ $document->page_count ? $document->page_count . ' page(s)' : 'Page count pending' }}</div>
        </div>
        <a href="{{ $document->file_url }}" target="_blank" class="fi-btn fi-btn-size-md fi-btn-color-gray inline-flex items-center rounded-lg px-3 py-2 text-sm font-semibold ring-1 ring-gray-950/10 dark:ring-white/20">
            Open Document
        </a>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">
        <iframe
            src="{{ $document->file_url }}"
            class="h-[75vh] w-full"
            title="{{ $document->original_name }}"
        ></iframe>
    </div>
</div>
