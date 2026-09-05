@props(['present' => false])

@if ($present)
    <span class="dc-chip bg-green-100 text-green-700 ring-1 ring-inset ring-green-600/20 dark:bg-green-500/15 dark:text-green-300 dark:ring-green-400/30">Present</span>
@else
    <span class="dc-chip bg-gray-200 text-gray-600 ring-1 ring-inset ring-gray-500/20 dark:bg-gray-500/20 dark:text-gray-300 dark:ring-gray-400/30">Absent</span>
@endif
