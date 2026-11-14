@php
    // Connect directly to Filament's form state
$rawStatus = isset($get) ? $get('journey_status') : $status ?? null;
$status = is_string($rawStatus) ? strtolower($rawStatus) : null;

$documentsSubmitted = isset($get) ? (bool) $get('documents_submitted') : $record->documents_submitted ?? false;

$displayStatus = $documentsSubmitted && $status === 'sanctioned' ? 'completed' : $status;

$steps = [
    ['key' => 'sfl', 'label' => 'SFL', 'number' => 1],
    ['key' => 'underwriting', 'label' => 'Underwriting', 'number' => 2],
    ['key' => 'approved', 'label' => 'Approved', 'number' => 3],
    ['key' => 'sanctioned', 'label' => 'Disbursed', 'number' => 4],
];

// $currentStep = match ($status) {
//     'sfl' => 1,
//     'underwriting' => 2,
//     'approved' => 3,
//     'sanctioned' => 4,
//     default => 0,
// };

// $currentStep = match ($status) {
//     'sfl' => 1,
//     'underwriting' => 2,
//     'approved' => 3,

//     'sanctioned', 'carry_forward', 'dropped' => 4,

//     'not_approved' => 2, // rejected in underwriting

//     default => 0,
// };

$currentStep = match ($displayStatus) {
    'sfl' => 1,
    'underwriting' => 2,
    'approved' => 3,
    'sanctioned', 'completed', 'carry_forward', 'dropped' => 4,

    'not_approved' => 2,

    default => 0,
};

$completedSteps = match ($displayStatus) {
    'sfl' => 1,
    'underwriting' => 2,
    'approved' => 3,

    'sanctioned', 'completed' => 4,

    'carry_forward' => 3,
    'dropped' => 3,

    'not_approved' => 2,

    default => 0,
};

// $completedSteps = match ($status) {
//     'sfl' => 1,
//     'underwriting' => 2,
//     'approved' => 3,
//     'sanctioned' => 4,
//     'carry_forward' => 3, // or 4 if you want Carry Forward as complete
//     'dropped' => 3,
//     'not_approved' => 2,
//     default => 0,
// };

$isRejected = $status === 'not_approved';

$progress = match ($currentStep) {
    1 => 0,
    2 => 33,
    3 => 66,
    4 => 100,
    default => 0,
};

// $statusLabel = $status ? ucwords(str_replace('_', ' ', $status)) : 'Pending Selection';
$statusLabel = match ($displayStatus) {
    'sanctioned' => 'Disbursed',
    'completed' => 'Completed',
    'carry_forward' => 'Carry Forward',
    'dropped' => 'Dropped',
    default => $displayStatus ? ucwords(str_replace('_', ' ', $displayStatus)) : 'Pending Selection',
};

$badgeClasses = match ($displayStatus) {
    'completed' => 'bg-green-100 text-green-700 dark:bg-green-500/10 dark:text-green-400',

    'sanctioned' => 'bg-green-100 text-green-700 dark:bg-green-500/10 dark:text-green-400',

    'approved' => 'bg-blue-100 text-blue-700 dark:bg-blue-500/10 dark:text-blue-400',

    'underwriting' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-500/10 dark:text-yellow-400',

    'carry_forward' => 'bg-orange-100 text-orange-700 dark:bg-orange-500/10 dark:text-orange-400',

    'dropped', 'not_approved' => 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-400',

    default => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
};

// $badgeClasses = match ($status) {
//     'sanctioned' => 'bg-green-100 text-green-700 dark:bg-green-500/10 dark:text-green-400',
//     'approved' => 'bg-blue-100 text-blue-700 dark:bg-blue-500/10 dark:text-blue-400',
//     'underwriting' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-500/10 dark:text-yellow-400',
//     'sfl' => 'bg-gray-100 text-gray-700 dark:bg-gray-500/10 dark:text-gray-400',
//     'not_approved' => 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-400',
//     default => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
// };

// $badgeClasses = match ($status) {
//     'sanctioned' => 'bg-green-100 text-green-700 dark:bg-green-500/10 dark:text-green-400',

//     'approved' => 'bg-blue-100 text-blue-700 dark:bg-blue-500/10 dark:text-blue-400',

//     'underwriting' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-500/10 dark:text-yellow-400',

//     'carry_forward' => 'bg-orange-100 text-orange-700 dark:bg-orange-500/10 dark:text-orange-400',

//     'dropped' => 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-400',

//     'not_approved' => 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-400',

//     default => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
    //     };

@endphp

<div class="fynn-card fynn-card--flush sticky top-4 z-50">

    <!-- Header -->
    <div class="flex items-center justify-between gap-4 border-b border-(--fynn-border-soft) bg-linear-to-b from-(--fynn-surface-2) to-(--fynn-surface) px-5 py-4 sm:px-6">
        <div class="flex min-w-0 items-center gap-3">
            <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-(--fynn-accent-soft) text-(--fynn-accent-text) ring-1 ring-inset ring-primary-500/20">
                <x-filament::icon icon="heroicon-o-map" class="size-5" />
            </span>
            <div class="min-w-0">
                <h2 class="text-base font-bold tracking-tight text-gray-950 sm:text-lg dark:text-white">
                    Customer Loan Journey
                </h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Current Stage : {{ $statusLabel }}
                </p>
            </div>
        </div>
        <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-3.5 py-1.5 text-sm font-semibold {{ $badgeClasses }}">
            <span class="size-1.5 rounded-full bg-current" aria-hidden="true"></span>
            {{ $statusLabel }}
        </span>
    </div>

    <div class="px-5 py-6 sm:px-8 sm:py-7">
        @if ($isRejected)
            <!-- Rejection State -->
            <div class="fynn-callout fynn-callout--danger flex items-start gap-3">
                <x-filament::icon icon="heroicon-o-no-symbol" class="mt-0.5 size-5 shrink-0 text-danger-600 dark:text-danger-400" />
                <div>
                    <h3 class="fynn-callout-title">Journey Terminated</h3>
                    <p class="mt-1 text-sm">This application was not approved.</p>
                </div>
            </div>
        @else
            <div class="relative">
                <!-- Progress Bar: runs behind the step circles, from the first one's centre to the last one's -->
                <div class="absolute inset-x-[12.5%] top-[1.375rem] -translate-y-1/2" aria-hidden="true">
                    <div class="h-1.5 rounded-full bg-gray-200 dark:bg-gray-700">
                        <div class="h-1.5 rounded-full bg-primary-600 transition-all duration-700 ease-out dark:bg-primary-500"
                            style="width: {{ $progress }}%;"></div>
                    </div>
                </div>

                <!-- Steps -->
                <div class="relative grid grid-cols-4 gap-4">
                    @foreach ($steps as $step)
                        @php
                            $active = $currentStep >= $step['number'];
                            $current = $currentStep === $step['number'];
                            $pending = $currentStep < $step['number'];
                        @endphp

                        <div class="text-center group">
                            <div
                                class="mx-auto flex h-11 w-11 items-center justify-center rounded-full border-2 text-sm font-bold shadow-xs transition-all duration-300
                                {{ $active ? 'border-primary-600 bg-primary-600 text-white dark:border-primary-500 dark:bg-primary-500' : '' }}
                                {{ $pending ? 'border-gray-200 bg-white text-gray-400 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-500' : '' }}
                                {{ $current ? 'ring-4 ring-primary-100 shadow-md scale-110 dark:ring-primary-500/20' : '' }}
                                ">
                                {{-- @if ($active && !$current)
                                    <svg class="h-6 w-6 text-white" viewBox="0 0 20 20" fill="currentColor"
                                        aria-hidden="true">
                                        <path fill-rule="evenodd"
                                            d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z"
                                            clip-rule="evenodd" />
                                    </svg>
                                @else
                                    <span class="{{ $current ? 'text-primary-600 dark:text-white' : '' }}">
                                        {{ $step['number'] }}
                                    </span>
                                @endif --}}

                                @if (
                                    ($active && !$current) ||
                                        ($displayStatus === 'sanctioned' && $step['number'] === 4) ||
                                        ($displayStatus === 'completed' && $step['number'] === 4))
                                    <svg class="h-5 w-5 text-white" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd"
                                            d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z"
                                            clip-rule="evenodd" />
                                    </svg>
                                @else
                                    <span>{{ $step['number'] }}</span>
                                @endif
                            </div>

                            <div
                                class="mt-3 text-xs font-semibold transition-colors duration-300 sm:text-sm
                                {{ $current ? 'text-(--fynn-accent-text)' : '' }}
                                {{ $active && !$current ? 'text-gray-900 dark:text-gray-200' : '' }}
                                {{ $pending ? 'text-gray-400 dark:text-gray-500' : '' }}
                            ">
                                {{ $step['label'] }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    <!-- Bottom Stats Bar -->
    <div class="grid grid-cols-4 divide-x divide-(--fynn-border-soft) border-t border-(--fynn-border-soft) bg-(--fynn-surface-2)">
        <div class="px-2 py-3 text-center">
            <div class="fynn-kpi-label">Current</div>
            <div class="mt-0.5 text-sm font-bold text-gray-950 dark:text-white">{{ $statusLabel }}</div>
        </div>

        <div class="px-2 py-3 text-center">
            <div class="fynn-kpi-label">Progress</div>
            <div class="mt-0.5 text-sm font-bold tabular-nums text-gray-950 dark:text-white">{{ $isRejected ? '0' : $progress }}%</div>
        </div>

        <div class="px-2 py-3 text-center">
            <div class="fynn-kpi-label">Completed</div>
            {{-- <div class="font-bold text-gray-900 dark:text-white">{{ $isRejected ? '0' : $currentStep }}/4</div> --}}
            <div class="mt-0.5 text-sm font-bold tabular-nums text-gray-950 dark:text-white">
                {{ $completedSteps }}/4
            </div>
        </div>

        <div class="px-2 py-3 text-center">
            <div class="fynn-kpi-label">Status</div>
            <div
                class="mt-0.5 text-sm font-bold {{ $isRejected ? 'text-red-600 dark:text-red-400' : ($status === 'sanctioned' ? 'text-green-600 dark:text-green-400' : 'text-(--fynn-accent-text)') }}">
                {{-- @if ($status === 'sanctioned')
                    Completed
                @elseif($status === 'carry_forward')
                    Carry Forward
                @elseif($status === 'dropped')
                    Dropped
                @elseif($status === 'not_approved')
                    Rejected
                @else
                    In Progress
                @endif --}}

                @if ($displayStatus === 'completed')
                    Completed
                @elseif ($displayStatus === 'sanctioned')
                    Disbursed
                @elseif ($displayStatus === 'carry_forward')
                    Carry Forward
                @elseif ($displayStatus === 'dropped')
                    Dropped
                @elseif ($displayStatus === 'not_approved')
                    Rejected
                @else
                    In Progress
                @endif
            </div>
        </div>
    </div>

</div>
