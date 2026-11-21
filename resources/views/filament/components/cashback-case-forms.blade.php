@php
    use App\Enums\CashbackRequestStatus;
    use App\Filament\Resources\CustomerCashbackRequests\CustomerCashbackRequestResource;

    /** @var \Illuminate\Support\Collection<int, \App\Models\CustomerCashbackRequest> $forms */
    /** @var int $currentId */
    /** @var float $cashbackBeforeForms */
    $when = fn ($at): string => $at ? $at->format('d M Y, h:i A') : '';
    $counted = $forms->filter(fn ($form): bool => $form->isLive());
    $total = round($cashbackBeforeForms + $counted->sum(fn ($form): float => $form->netAmount()), 2);
@endphp

<div class="space-y-2">
    <div class="fynn-table-wrap overflow-x-auto">
        <table class="fynn-table">
            <thead>
                <tr>
                    <th>Voucher</th>
                    <th class="fynn-num">Amount</th>
                    <th>Filled by</th>
                    <th>Supervisor sign-off</th>
                    <th>Approval</th>
                    <th>Payment</th>
                    <th class="fynn-num">Counts</th>
                </tr>
            </thead>
            <tbody>
                @if ($cashbackBeforeForms > 0)
                    <tr class="is-muted">
                        <td colspan="6">Cashback entered on the case before its first form</td>
                        <td class="fynn-num">₹{{ indianNumberFormat($cashbackBeforeForms) }}</td>
                    </tr>
                @endif

                @foreach ($forms as $form)
                    <tr @class(['is-highlighted' => $form->getKey() === $currentId, 'is-muted' => ! $form->isLive()])>
                        <td>
                            <a href="{{ CustomerCashbackRequestResource::getUrl('view', ['record' => $form]) }}" class="font-semibold text-primary-600 hover:underline dark:text-primary-400">{{ $form->voucher_no }}</a>
                            <div class="fynn-list-meta">
                                {{ $form->type->label() }}
                                @if ($form->isRevision())
                                    · Revision {{ $form->revision_no }} — second payment
                                @endif
                                @if ($form->getKey() === $currentId)
                                    · this form
                                @endif
                            </div>
                        </td>
                        <td class="fynn-num">
                            ₹{{ indianNumberFormat($form->effectiveAmount()) }}
                            @if ($form->approved_amount !== null && (float) $form->approved_amount !== (float) $form->amount)
                                <div class="fynn-list-meta">Asked ₹{{ indianNumberFormat($form->amount) }}</div>
                            @endif
                        </td>
                        <td>
                            {{ $form->requester?->name ?? 'Former user' }}
                            <div class="fynn-list-meta">{{ $when($form->created_at) }}</div>
                        </td>
                        <td>
                            @if ($form->manager_approved_at)
                                {{ $form->managerApprover?->name ?? '—' }}
                                <div class="fynn-list-meta">{{ $when($form->manager_approved_at) }}</div>
                            @elseif ($form->escalated_at)
                                {{ $form->supervisor?->emp_name ?? 'Supervisor' }}
                                <div class="fynn-list-meta">Not signed within 24 h — moved to the Admin {{ $when($form->escalated_at) }}</div>
                            @elseif ($form->status === CashbackRequestStatus::PendingManager)
                                {{ $form->supervisor?->emp_name ?? 'Supervisor' }}
                                <div class="fynn-list-meta">Waiting · due {{ $when($form->supervisor_due_at) }}</div>
                            @else
                                <span class="fynn-list-meta">Not needed</span>
                            @endif
                        </td>
                        <td>
                            <x-filament::badge :color="$form->status->color()" size="sm">{{ $form->status->label() }}</x-filament::badge>
                            <div class="fynn-list-meta">
                                @switch ($form->status)
                                    @case (CashbackRequestStatus::Approved)
                                        {{ $form->approver?->name ?? '—' }} · {{ $when($form->approved_at) }}
                                        @break
                                    @case (CashbackRequestStatus::Rejected)
                                        {{ $form->rejecter?->name ?? '—' }} · {{ $when($form->rejected_at) }}
                                        @break
                                    @case (CashbackRequestStatus::Retrieved)
                                        {{ $form->retriever?->name ?? '—' }} · {{ $when($form->retrieved_at) }}
                                        @break
                                @endswitch
                            </div>
                        </td>
                        <td>
                            @if ($form->isApproved())
                                <x-filament::badge :color="$form->payment_status->color()" size="sm">{{ $form->payment_status->label() }}</x-filament::badge>
                                @if ($form->isPaid())
                                    <div class="fynn-list-meta">
                                        {{ $form->transaction_id ? 'UTR '.$form->transaction_id : ($form->payment_mode?->label() ?? '') }}
                                        @if ($form->payment_date)
                                            · {{ $form->payment_date->format('d M Y') }}
                                        @endif
                                        @if ($form->paid_by_name)
                                            · by {{ $form->paid_by_name }}
                                        @endif
                                    </div>
                                @endif
                                @if ($form->isReturned())
                                    <div class="fynn-list-meta text-danger-600 dark:text-danger-400">
                                        Returned ₹{{ indianNumberFormat($form->returnedAmount()) }} on {{ $form->return_date?->format('d M Y') }}
                                        @if ($form->return_reference)
                                            · UTR {{ $form->return_reference }}
                                        @endif
                                        · approved by {{ $form->returnReviewer?->name ?? '—' }} · {{ $when($form->return_reviewed_at) }}
                                    </div>
                                @elseif ($form->hasPendingReturn())
                                    <div class="fynn-list-meta text-warning-600 dark:text-warning-400">
                                        Return of ₹{{ indianNumberFormat($form->returned_amount) }} asked by {{ $form->returnRequester?->name ?? '—' }} — waiting for the Admin
                                    </div>
                                @endif
                            @else
                                <span class="fynn-list-meta">—</span>
                            @endif
                        </td>
                        <td class="fynn-num">
                            @if ($form->isLive())
                                ₹{{ indianNumberFormat($form->netAmount()) }}
                            @else
                                <span class="fynn-list-meta">Not counted</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="6" class="text-start">Cashback on this case ({{ $counted->count() }} {{ str('form')->plural($counted->count()) }} counted)</th>
                    <th class="fynn-num">₹{{ indianNumberFormat($total) }}</th>
                </tr>
            </tfoot>
        </table>
    </div>

    <p class="fynn-note">Pending and approved forms count towards the case's cashback, less any return the Admin approved; rejected and retrieved forms do not.</p>
</div>
