@php
    use App\Filament\Resources\CustomerCashbackRequests\CustomerCashbackRequestResource;

    /** @var \Illuminate\Support\Collection<int, \App\Models\CustomerCashbackRequest> $requests */
    /** @var float $cashbackBeforeForms */
@endphp

<div class="space-y-3">
    @if ($requests->isNotEmpty())
        <p class="fynn-list-meta">
            @if ($cashbackBeforeForms > 0)
                ₹{{ indianNumberFormat($cashbackBeforeForms) }} cashback was on this case before its first form and still counts.
            @endif
            Each cashback is its own form, and the case's cashback is their total. Open a voucher to retrieve an expected cashback, change an approved amount, record the payment or ask for a cashback the customer returned to be recorded (the Admin approves it).
        </p>
    @endif

    @forelse ($requests as $request)
        <div class="group/tl flex gap-3 text-sm">
            <div class="flex flex-col items-center" aria-hidden="true">
                <span class="mt-1.5 size-2.5 shrink-0 rounded-full bg-primary-500 ring-4 ring-primary-500/15"></span>
                <span class="mt-1.5 w-px flex-1 bg-gray-200 group-last/tl:hidden dark:bg-white/10"></span>
            </div>

            <div class="min-w-0 flex-1 pb-4 group-last/tl:pb-0">
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ CustomerCashbackRequestResource::getUrl('view', ['record' => $request]) }}" class="font-semibold text-primary-600 hover:underline dark:text-primary-400">
                        {{ $request->voucher_no }}
                    </a>
                    <x-filament::badge :color="$request->type->color()" size="sm">{{ $request->type->label() }}</x-filament::badge>
                    <span class="font-semibold text-gray-950 dark:text-white">₹{{ indianNumberFormat($request->effectiveAmount()) }}</span>
                    @if ($request->declaresSubvention())
                        <span class="fynn-list-meta">subvention ₹{{ indianNumberFormat($request->subvention_amount) }}</span>
                    @endif
                    <x-filament::badge :color="$request->status->color()" size="sm">{{ $request->status->label() }}</x-filament::badge>
                    @if ($request->isApproved())
                        <x-filament::badge :color="$request->payment_status->color()" size="sm">{{ $request->payment_status->label() }}</x-filament::badge>
                    @endif
                    @if ($request->isRevision())
                        <span class="fynn-list-meta">Revision {{ $request->revision_no }} — second payment</span>
                    @endif
                    @if ($tat = $request->supervisorTatLabel())
                        <span class="fynn-list-meta">{{ $request->supervisor?->emp_name }} · {{ $tat }}</span>
                    @elseif ($request->escalated_at && $request->status === \App\Enums\CashbackRequestStatus::PendingAdmin)
                        <span class="fynn-list-meta">Supervisor missed 24 h — with the Admin</span>
                    @endif
                </div>

                @foreach (array_filter([
                    $request->rejection_reason ? 'Rejected: '.$request->rejection_reason : null,
                    $request->retrieval_reason ? 'Retrieved: '.$request->retrieval_reason : null,
                    $request->payment_remarks ? 'Payment: '.$request->payment_remarks : null,
                    $request->isReturned() ? 'Customer returned ₹'.indianNumberFormat($request->returnedAmount()).' on '.$request->return_date?->format('d M Y').' (approved by the Admin): '.$request->return_reason : null,
                    $request->hasPendingReturn() ? 'Return of ₹'.indianNumberFormat($request->returned_amount).' waiting for the Admin: '.$request->return_reason : null,
                ]) as $line)
                    <p class="mt-1 whitespace-pre-line text-gray-700 dark:text-gray-200">{{ $line }}</p>
                @endforeach

                <p class="fynn-list-meta mt-1">
                    Filled by {{ $request->requester?->name ?? 'Former user' }} · {{ $request->created_at?->format('d M Y, h:i A') }}
                    @if ($request->manager_approved_at)
                        · Signed off by {{ $request->managerApprover?->name ?? '—' }} · {{ $request->manager_approved_at->format('d M Y, h:i A') }}
                    @endif
                    @if ($request->isApproved() && $request->approved_at)
                        · Approved by {{ $request->approver?->name ?? '—' }} · {{ $request->approved_at->format('d M Y, h:i A') }}
                    @endif
                    @if ($request->isPaid() && $request->transaction_id)
                        · UTR {{ $request->transaction_id }}
                    @endif
                </p>
            </div>
        </div>
    @empty
        <div class="fynn-empty fynn-empty--compact">
            <span class="fynn-empty-icon"><x-filament::icon icon="heroicon-o-receipt-refund" /></span>
            <span>No cashback form on this file yet. Declare cashback or expected cashback and fill the form to send it for approval.</span>
        </div>
    @endforelse
</div>
