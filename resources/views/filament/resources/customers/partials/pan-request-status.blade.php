@if ($request->status === 'approved')

    <span class="inline-flex items-center gap-1.5 rounded-full bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-700 dark:bg-green-500/10 dark:text-green-400">
        <span class="h-1.5 w-1.5 rounded-full bg-green-500"></span>
        Approved
    </span>

@elseif ($request->status === 'rejected')

    <span class="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700 dark:bg-red-500/10 dark:text-red-400">
        <span class="h-1.5 w-1.5 rounded-full bg-red-500"></span>
        Rejected
    </span>

@else

    <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">
        <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
        Pending
    </span>

@endif
