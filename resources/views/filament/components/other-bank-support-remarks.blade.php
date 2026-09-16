@php
    /** @var array<string, array{label: string, remarks: \Illuminate\Support\Collection<int, \App\Models\OtherBankSupportRemark>}> $groups */
    $remarkCount = collect($groups)->sum(fn (array $group): int => $group['remarks']->count());
@endphp

<div>
    @if ($remarkCount === 0)
        <p class="text-sm text-gray-500 dark:text-gray-400">
            No remarks from Other Bank Support yet.
        </p>
    @else
        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($groups as $group)
                <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <div class="mb-2 text-xs font-bold uppercase tracking-wide text-gray-600 dark:text-gray-300">
                        {{ $group['label'] }}
                    </div>

                    <div class="space-y-3">
                        @forelse ($group['remarks'] as $remark)
                            <div class="border-l-2 border-primary-500 pl-3 text-sm">
                                <p class="whitespace-pre-line text-gray-950 dark:text-white">{{ $remark->remark }}</p>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    {{ $remark->user?->name ?? 'Former user' }} · {{ $remark->created_at?->format('d M Y, h:i A') }}
                                </p>
                            </div>
                        @empty
                            <p class="text-xs text-gray-400 dark:text-gray-500">No remarks for this step.</p>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
