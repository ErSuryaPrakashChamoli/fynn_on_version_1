<div class="w-full min-w-0">

    {{-- Header --}}
    <div class="mb-3 flex items-center justify-between gap-3">
        <div class="min-w-0">
            <h3 class="truncate text-sm font-semibold text-gray-950 dark:text-white">
                PAN Request History
            </h3>

            <p class="text-xs text-gray-500 dark:text-gray-400">
                Previous duplicate PAN requests
            </p>
        </div>

        <span
            class="shrink-0 rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300">
            {{ $requests->count() }}
        </span>
    </div>


    {{-- Requests --}}
    <div class="space-y-3">

        @forelse ($requests as $request)
            <div
                class="w-full min-w-0 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">

                {{-- Top --}}
                <div class="flex min-w-0 items-start justify-between gap-3">

                    <div class="min-w-0 flex-1">

                        <div class="truncate text-sm font-semibold text-gray-900 dark:text-white">
                            {{ $request->request_no ?? 'PR-' . str_pad($request->id, 6, '0', STR_PAD_LEFT) }}
                        </div>

                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ $request->created_at?->format('d M Y, h:i A') }}
                        </div>

                    </div>

                    {{-- Status --}}
                    <div class="shrink-0">

                        @if ($request->status === 'approved')
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-700 dark:bg-green-500/10 dark:text-green-400">
                                <span class="h-1.5 w-1.5 rounded-full bg-green-500"></span>
                                Approved
                            </span>
                        @elseif ($request->status === 'rejected')
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700 dark:bg-red-500/10 dark:text-red-400">
                                <span class="h-1.5 w-1.5 rounded-full bg-red-500"></span>
                                Rejected
                            </span>
                        @else
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">
                                <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                                Pending
                            </span>
                        @endif

                    </div>

                </div>


                {{-- Details --}}
                <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">

                    <div class="min-w-0">
                        <div class="text-[11px] font-medium uppercase tracking-wide text-gray-400">
                            Requested By
                        </div>

                        <div class="mt-1 truncate text-sm text-gray-800 dark:text-gray-200">
                            {{ $request->requested_by_name ?? '-' }}
                        </div>
                    </div>


                    <div class="min-w-0">
                        <div class="text-[11px] font-medium uppercase tracking-wide text-gray-400">
                            Bank
                        </div>

                        <div class="mt-1 truncate text-sm text-gray-800 dark:text-gray-200">
                            {{ $request->requested_bank_name ?? '-' }}
                        </div>
                    </div>


                    <div class="min-w-0">
                        <div class="text-[11px] font-medium uppercase tracking-wide text-gray-400">
                            Loan Type
                        </div>

                        <div class="mt-1 truncate text-sm text-gray-800 dark:text-gray-200">
                            {{ ucwords(str_replace('_', ' ', $request->requested_loan_type)) }}
                        </div>
                    </div>


                    <div class="min-w-0">
                        <div class="text-[11px] font-medium uppercase tracking-wide text-gray-400">
                            Request ID
                        </div>

                        <div class="mt-1 truncate text-sm text-gray-800 dark:text-gray-200">
                            PR-{{ str_pad($request->id, 6, '0', STR_PAD_LEFT) }}
                        </div>
                    </div>

                </div>


                {{-- Reason --}}
                @if ($request->reason)
                    <div class="mt-4 rounded-lg bg-gray-50 p-3 dark:bg-gray-800/70">

                        <div class="text-[11px] font-medium uppercase tracking-wide text-gray-400">
                            Reason
                        </div>

                        <div class="mt-1 break-words text-sm text-gray-700 dark:text-gray-300">
                            {{ $request->reason }}
                        </div>

                    </div>
                @endif


                {{-- Admin Remarks --}}
                @if ($request->remarks)
                    <div class="mt-3 rounded-lg bg-gray-50 p-3 dark:bg-gray-800/70">

                        <div class="text-[11px] font-medium uppercase tracking-wide text-gray-400">
                            Admin Remarks
                        </div>

                        <div class="mt-1 break-words text-sm text-gray-700 dark:text-gray-300">
                            {{ $request->remarks }}
                        </div>

                    </div>
                @endif


                {{-- Continue --}}
                @if ($request->status === 'approved')
                    <div class="mt-4 border-t border-gray-100 pt-3 dark:border-gray-800">

                        <a href="{{ \App\Filament\Resources\Customers\CustomerResource::getUrl('create', [
                            'pan_request' => $request->id,
                        ]) }}"
                            class="!flex !w-full !items-center !justify-center !gap-2 !rounded-lg
                            !bg-primary-600 !px-4 !py-2.5 !text-sm !font-semibold
                            !leading-5 !text-white
                            !shadow-sm
                            !transition-all !duration-200 !ease-in-out
                            hover:!bg-primary-700
                            hover:!shadow-md
                            hover:!scale-[1.01]
                            active:!scale-[0.99]"
                            style="height: 42px; min-height: 42px; max-height: 42px;">

                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                                stroke="currentColor" class="!h-4 !w-4 !shrink-0 !transition-transform !duration-200"
                                style="width: 16px; height: 16px;">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                            </svg>

                            <span>
                                Continue Application
                            </span>

                        </a>

                    </div>
                @endif

            </div>

        @empty

            <div
                class="rounded-xl border border-gray-200 bg-white p-8 text-center dark:border-gray-700 dark:bg-gray-900">

                <p class="text-sm font-medium text-gray-700 dark:text-gray-300">
                    No PAN requests found
                </p>

                <p class="mt-1 text-xs text-gray-400">
                    Previous requests will appear here.
                </p>

            </div>
        @endforelse

    </div>

</div>
