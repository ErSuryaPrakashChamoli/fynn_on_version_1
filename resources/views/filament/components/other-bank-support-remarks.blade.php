@php
    /** @var array<string, array{label: string, remarks: \Illuminate\Support\Collection<int, \App\Models\OtherBankSupportRemark>}> $groups */
    $remarkCount = collect($groups)->sum(fn (array $group): int => $group['remarks']->count());
@endphp

<div>
    @if ($remarkCount === 0)
        <div class="fynn-empty fynn-empty--compact">
            <span class="fynn-empty-icon"><x-filament::icon icon="heroicon-o-chat-bubble-left-right" /></span>
            <span>No remarks from Other Bank Support yet.</span>
        </div>
    @else
        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($groups as $group)
                <div class="fynn-card">
                    <div class="fynn-subheading">
                        {{ $group['label'] }}
                    </div>

                    <div class="space-y-3">
                        @forelse ($group['remarks'] as $remark)
                            <div class="rounded-e-lg border-s-2 border-primary-500 bg-gray-50 px-3 py-2 text-sm dark:bg-white/5">
                                <p class="whitespace-pre-line text-gray-950 dark:text-white">{{ $remark->remark }}</p>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    {{ $remark->user?->name ?? 'Former user' }} · {{ $remark->created_at?->format('d M Y, h:i A') }}
                                </p>
                            </div>
                        @empty
                            <p class="text-xs text-gray-500 dark:text-gray-400">No remarks for this step.</p>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
