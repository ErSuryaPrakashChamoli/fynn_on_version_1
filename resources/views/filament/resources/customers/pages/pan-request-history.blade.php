<div class="w-full min-w-0">

    {{-- Header --}}
    <div class="mb-4 flex items-center justify-between gap-3">
        <div class="min-w-0">
            <h3 class="fynn-subheading mb-0.5">
                PAN Request History
            </h3>

            <p class="fynn-list-meta">
                Previous duplicate PAN requests
            </p>
        </div>

        <span class="fynn-chip shrink-0 tabular-nums">
            <x-filament::icon icon="heroicon-m-clipboard-document-list" />
            {{ $requests->count() }}
        </span>
    </div>


    {{-- Requests: newest first, one card per request on a vertical timeline --}}
    <div>

        @forelse ($requests as $request)
            <div class="group/tl flex gap-3">

                <div class="flex flex-col items-center" aria-hidden="true">
                    <span class="mt-5 size-2.5 shrink-0 rounded-full bg-primary-500 ring-4 ring-primary-500/15"></span>
                    <span class="mt-1.5 w-px flex-1 bg-gray-200 group-last/tl:hidden dark:bg-white/10"></span>
                </div>

                <div class="min-w-0 flex-1 pb-4 group-last/tl:pb-0">
                    <div class="fynn-card w-full p-4">

                        {{-- Top --}}
                        <div class="flex min-w-0 items-start justify-between gap-3">

                            <div class="min-w-0 flex-1">

                                <div class="truncate text-sm font-semibold text-gray-950 dark:text-white">
                                    {{ $request->request_no ?? 'PR-' . str_pad($request->id, 6, '0', STR_PAD_LEFT) }}
                                </div>

                                <div class="fynn-list-meta mt-0.5">
                                    {{ $request->created_at?->format('d M Y, h:i A') }}
                                </div>

                            </div>

                            {{-- Status --}}
                            <div class="shrink-0">

                                @if ($request->status === 'approved')
                                    <span
                                        class="inline-flex items-center gap-1.5 rounded-full bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-700 ring-1 ring-inset ring-green-600/20 dark:bg-green-500/10 dark:text-green-400 dark:ring-green-400/20">
                                        <span class="h-1.5 w-1.5 rounded-full bg-green-500"></span>
                                        Approved
                                    </span>
                                @elseif ($request->status === 'rejected')
                                    <span
                                        class="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-600/20 dark:bg-red-500/10 dark:text-red-400 dark:ring-red-400/20">
                                        <span class="h-1.5 w-1.5 rounded-full bg-red-500"></span>
                                        Rejected
                                    </span>
                                @else
                                    <span
                                        class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-400/20">
                                        <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                                        Pending
                                    </span>
                                @endif

                            </div>

                        </div>


                        {{-- Details --}}
                        <dl class="fynn-dl mt-4 grid-cols-1 sm:grid-cols-2">

                            <div class="fynn-dl-item">
                                <dt>
                                    Requested By
                                </dt>

                                <dd class="truncate">
                                    {{ $request->requested_by_name ?? '-' }}
                                </dd>
                            </div>


                            <div class="fynn-dl-item">
                                <dt>
                                    Bank
                                </dt>

                                <dd class="truncate">
                                    {{ $request->requested_bank_name ?? '-' }}
                                </dd>
                            </div>


                            <div class="fynn-dl-item">
                                <dt>
                                    Loan Type
                                </dt>

                                <dd class="truncate">
                                    {{ filled($request->requested_loan_type) ? ucwords(str_replace('_', ' ', $request->requested_loan_type)) : '-' }}
                                </dd>
                            </div>


                            <div class="fynn-dl-item">
                                <dt>
                                    Request ID
                                </dt>

                                <dd class="truncate font-mono">
                                    PR-{{ str_pad($request->id, 6, '0', STR_PAD_LEFT) }}
                                </dd>
                            </div>

                        </dl>


                        {{-- Reason --}}
                        @if ($request->reason)
                            <div class="fynn-callout fynn-callout--neutral mt-4 px-4 py-3">

                                <div class="fynn-kpi-label">
                                    Reason
                                </div>

                                <div class="mt-1 break-words text-sm">
                                    {{ $request->reason }}
                                </div>

                            </div>
                        @endif


                        {{-- Admin Remarks --}}
                        @if ($request->remarks)
                            <div class="fynn-callout fynn-callout--info mt-3 px-4 py-3">

                                <div class="fynn-kpi-label">
                                    Admin Remarks
                                </div>

                                <div class="mt-1 break-words text-sm">
                                    {{ $request->remarks }}
                                </div>

                            </div>
                        @endif


                        {{-- Continue --}}
                        @if ($request->status === 'approved' && ! $request->isUsed())
                            <div class="mt-4 border-t border-(--fynn-border-soft) pt-3">

                                <x-filament::button
                                    tag="a"
                                    :href="$request->continueUrl()"
                                    icon="heroicon-m-plus"
                                    class="w-full"
                                >
                                    {{ $request->isForLead() ? 'Continue Lead' : 'Continue Application' }}
                                </x-filament::button>

                            </div>
                        @endif

                    </div>
                </div>

            </div>

        @empty

            <div class="fynn-empty fynn-empty--compact">

                <span class="fynn-empty-icon"><x-filament::icon icon="heroicon-o-clipboard-document-list" /></span>

                <p class="fynn-empty-title">
                    No PAN requests found
                </p>

                <p class="text-xs">
                    Previous requests will appear here.
                </p>

            </div>
        @endforelse

    </div>

</div>
