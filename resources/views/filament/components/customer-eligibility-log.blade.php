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
        <div class="fynn-callout fynn-callout--warning flex items-start gap-3">
            <x-filament::icon icon="heroicon-o-clock" class="mt-0.5 size-5 shrink-0 text-warning-600 dark:text-warning-400" />
            <div class="min-w-0">
                <p class="fynn-callout-title">Eligibility request waiting for the Admin</p>
                <p class="mt-1 whitespace-pre-line text-gray-950 dark:text-white">{{ $pendingRequest->reason }}</p>
                <p class="fynn-list-meta mt-1">
                    {{ $pendingRequest->requester?->name ?? 'Former user' }} · {{ $pendingRequest->created_at?->format('d M Y, h:i A') }}
                </p>
            </div>
        </div>
    @endif

    <div>
        <div class="fynn-subheading">
            Eligibility log
        </div>

        {{-- A vertical timeline, newest entry first. --}}
        @forelse ($logs as $log)
            <div class="group/tl flex gap-3 text-sm">
                <div class="flex flex-col items-center" aria-hidden="true">
                    <span class="mt-1.5 size-2.5 shrink-0 rounded-full bg-primary-500 ring-4 ring-primary-500/15"></span>
                    <span class="mt-1.5 w-px flex-1 bg-gray-200 group-last/tl:hidden dark:bg-white/10"></span>
                </div>

                <div class="min-w-0 flex-1 pb-4 group-last/tl:pb-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <x-filament::badge :color="$log->event->color()" size="sm">
                            {{ $log->event->label() }}
                        </x-filament::badge>

                        @if ($log->from_status && $log->to_status && $log->from_status !== $log->to_status)
                            <span class="font-semibold text-gray-950 dark:text-white">
                                {{ CustomerEligibilityService::statusLabel($log->from_status) }}
                                →
                                {{ CustomerEligibilityService::statusLabel($log->to_status) }}
                            </span>
                        @elseif ($log->to_status)
                            <span class="font-semibold text-gray-950 dark:text-white">
                                {{ CustomerEligibilityService::statusLabel($log->to_status) }}
                            </span>
                        @endif
                    </div>

                    @if ($log->remarks)
                        <p class="mt-1 whitespace-pre-line text-gray-700 dark:text-gray-200">{{ $log->remarks }}</p>
                    @endif

                    <p class="fynn-list-meta mt-1">
                        {{ $log->user?->name ?? 'System' }} · {{ $log->created_at?->format('d M Y, h:i A') }}
                    </p>
                </div>
            </div>
        @empty
            <div class="fynn-empty fynn-empty--compact">
                <span class="fynn-empty-icon"><x-filament::icon icon="heroicon-o-queue-list" /></span>
                <span>No eligibility changes recorded yet.</span>
            </div>
        @endforelse
    </div>
</div>
