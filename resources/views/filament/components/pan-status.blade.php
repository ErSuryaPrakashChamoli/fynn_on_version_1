@if($get('pan_status') === 'checking')
    <div class="inline-flex items-center gap-1.5 rounded-full bg-(--fynn-accent-softer) px-3 py-1 text-sm font-medium text-(--fynn-accent-text) ring-1 ring-inset ring-primary-500/25">
        ⏳ Checking PAN...
    </div>
@elseif($get('pan_status') === 'available')
    <div class="inline-flex items-center gap-1.5 rounded-full bg-success-50 px-3 py-1 text-sm font-medium text-success-700 ring-1 ring-inset ring-success-600/20 dark:bg-success-500/10 dark:text-success-400 dark:ring-success-400/20">
        ✅ PAN Available
    </div>
@elseif($get('pan_status') === 'exists')
    <div class="inline-flex items-center gap-1.5 rounded-full bg-danger-50 px-3 py-1 text-sm font-medium text-danger-700 ring-1 ring-inset ring-danger-600/20 dark:bg-danger-500/10 dark:text-danger-400 dark:ring-danger-400/20">
        ⚠️ Existing Customer Found
    </div>
@endif
