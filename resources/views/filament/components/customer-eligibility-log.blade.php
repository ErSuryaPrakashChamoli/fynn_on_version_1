@php
    use App\Services\CustomerEligibilityService;

    /** @var string|null $status */
    /** @var \App\Models\CustomerEligibilityRequest|null $pendingRequest */
    /** @var \Illuminate\Support\Collection<int, \App\Models\CustomerEligibilityLog> $logs */
@endphp

<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-2 text-sm">
        <span class="text-gray-500 dark:text-gray-400">Current status:</span>
        <x-filament::badge :color="CustomerEligibilityService::statusColor($status)">
            {{ CustomerEligibilityService::statusLabel($status) }}
        </x-filament::badge>
    </div>

    @if ($pendingRequest)
        <div class="rounded-xl border border-warning-300 bg-warning-50 p-3 text-sm dark:border-warning-500/40 dark:bg-warning-500/10">
            <p class="font-semibold text-warning-700 dark:text-warning-400">Eligibility request waiting for the Admin</p>
            <p class="mt-1 whitespace-pre-line text-gray-950 dark:text-white">{{ $pendingRequest->reason }}</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ $pendingRequest->requester?->name ?? 'Former user' }} · {{ $pendingRequest->created_at?->format('d M Y, h:i A') }}
            </p>
        </div>
    @endif

    <div>
        <div class="mb-2 text-xs font-bold uppercase tracking-wide text-gray-600 dark:text-gray-300">
            Eligibility log
        </div>

        @forelse ($logs as $log)
            <div class="mb-3 border-l-2 border-primary-500 pl-3 text-sm">
                <div class="flex flex-wrap items-center gap-2">
                    <x-filament::badge :color="$log->event->color()" size="sm">
                        {{ $log->event->label() }}
                    </x-filament::badge>

                    @if ($log->from_status && $log->to_status && $log->from_status !== $log->to_status)
                        <span class="text-gray-950 dark:text-white">
                            {{ CustomerEligibilityService::statusLabel($log->from_status) }}
                            →
                            {{ CustomerEligibilityService::statusLabel($log->to_status) }}
                        </span>
                    @elseif ($log->to_status)
                        <span class="text-gray-950 dark:text-white">
                            {{ CustomerEligibilityService::statusLabel($log->to_status) }}
                        </span>
                    @endif
                </div>

                @if ($log->remarks)
                    <p class="mt-1 whitespace-pre-line text-gray-700 dark:text-gray-200">{{ $log->remarks }}</p>
                @endif

                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ $log->user?->name ?? 'System' }} · {{ $log->created_at?->format('d M Y, h:i A') }}
                </p>
            </div>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">No eligibility changes recorded yet.</p>
        @endforelse
    </div>
</div>
