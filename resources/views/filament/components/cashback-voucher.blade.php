@php
    use App\Enums\CashbackPaymentMode;
    use App\Enums\CashbackPaymentStatus;
    use App\Enums\CashbackReturnStatus;

    /** @var \App\Models\CustomerCashbackRequest $voucher */
    $customer = $voucher->customer;
    $date = fn ($value): string => $value ? $value->format('d/m/Y') : '';
    $signed = fn ($user, $at): string => $user ? 'Digitally signed · '.$at?->format('d/m/Y, h:i A') : '';
    $mode = $voucher->payment_mode;
    $chain = [
        'ARO Name' => [$voucher->aro, null],
        'TL Name' => [$voucher->teamLeader, $voucher->chainSignature($voucher->team_leader_employee_id)],
        'Manager Name' => [$voucher->manager, $voucher->chainSignature($voucher->manager_employee_id)],
        'Cluster Manager Name' => [$voucher->clusterManager, $voucher->chainSignature($voucher->cluster_manager_employee_id)],
    ];
    $toFill = 'To be filled by Admin / Accounts';
@endphp

{{-- Styles are inline (no theme build needed) and scoped to the voucher. --}}
<style>
    .cashback-voucher { color: #111827; background: #fff; border: 1px solid #e5e7eb; border-radius: 0.75rem; padding: 2rem 1.5rem; max-width: 52rem; margin: 0 auto; font-size: 0.875rem; }
    .cashback-voucher__head { text-align: center; line-height: 1.6; font-weight: 700; }
    .cashback-voucher__title { text-align: center; font-weight: 700; margin: 1.5rem 0; font-size: 1rem; text-decoration: underline; }
    .cashback-voucher__meta { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1rem; }
    .cashback-voucher table { width: 100%; border-collapse: collapse; }
    .cashback-voucher td { border: 1px solid #111827; padding: 0.5rem 0.625rem; vertical-align: middle; }
    .cashback-voucher td.cv-label { font-weight: 700; width: 38%; }
    .cashback-voucher td.cv-sign { font-weight: 700; width: 12%; }
    .cashback-voucher td.cv-sign-value { width: 20%; font-size: 0.75rem; color: #047857; }
    .cashback-voucher tr.cv-gap td { height: 1.75rem; border-left: 1px solid #111827; border-right: 1px solid #111827; }
    .cashback-voucher .cv-modes { display: flex; gap: 0; }
    .cashback-voucher .cv-mode { border: 1px solid #111827; padding: 0.125rem 0.625rem; font-weight: 700; margin-right: -1px; }
    .cashback-voucher .cv-mode--on { background: #111827; color: #fff; }
    .cashback-voucher .cv-muted { color: #6b7280; font-size: 0.75rem; }
    .cashback-voucher .cv-status { font-weight: 700; }
    .cashback-voucher .cv-status--paid { color: #047857; }
    .cashback-voucher .cv-status--on_hold { color: #b45309; }
    .cashback-voucher .cv-status--not_paid { color: #4b5563; }
    .cashback-voucher td.cv-sign-value.cv-tone--waiting { color: #b45309; }
    .cashback-voucher td.cv-sign-value.cv-tone--missed { color: #b91c1c; }
    .cashback-voucher .cv-to-fill { color: #9ca3af; font-style: italic; font-size: 0.75rem; }
    .cashback-voucher .cv-revision { text-align: center; margin: -1rem 0 1rem; font-weight: 600; color: #b45309; }
    .cashback-voucher .cv-returned { font-weight: 700; color: #b91c1c; }
    .dark .cashback-voucher { color: #f3f4f6; background: #18181b; border-color: rgba(255, 255, 255, 0.1); }
    .dark .cashback-voucher td, .dark .cashback-voucher .cv-mode, .dark .cashback-voucher tr.cv-gap td { border-color: #71717a; }
    .dark .cashback-voucher .cv-mode--on { background: #f3f4f6; color: #18181b; }
    .dark .cashback-voucher td.cv-sign-value, .dark .cashback-voucher .cv-status--paid { color: #34d399; }
    .dark .cashback-voucher .cv-muted { color: #a1a1aa; }
    .dark .cashback-voucher td.cv-sign-value.cv-tone--waiting, .dark .cashback-voucher .cv-revision { color: #fbbf24; }
    .dark .cashback-voucher td.cv-sign-value.cv-tone--missed, .dark .cashback-voucher .cv-returned { color: #f87171; }
    @media print {
        body * { visibility: hidden !important; }
        .cashback-voucher, .cashback-voucher * { visibility: visible !important; }
        .cashback-voucher { position: absolute; inset: 0 auto auto 0; width: 100%; max-width: none; border: 0; color: #000 !important; background: #fff !important; }
        .cashback-voucher td, .cashback-voucher .cv-mode { border-color: #000 !important; }
        .cashback-voucher .cv-to-fill { visibility: hidden !important; }
    }
</style>

<div class="cashback-voucher">
    <div class="cashback-voucher__head">
        <div>FYNNEDGE ADVISORY (OPC) PVT. LTD.</div>
        <div>Registered Office : Plot No- A-70, 1<sup>st</sup> Floor, Sector 2, Noida,</div>
        <div>Gautam Buddha Nagar , Uttar Pradesh - 201301</div>
    </div>

    <div class="cashback-voucher__title">Acknowledgment</div>

    @if ($voucher->isRevision())
        <div class="cv-revision">{{ $voucher->revisionLabel() }} — second payment on the same cashback</div>
    @endif

    <div class="cashback-voucher__meta">
        <div><strong>Voucher No :</strong> {{ $voucher->voucher_no }}</div>
        <div><strong>Issue Date :</strong> {{ $date($voucher->created_at) }}</div>
    </div>

    <table>
        <tr><td class="cv-label">Name</td><td colspan="3">{{ $customer?->customer_name }}</td></tr>
        <tr><td class="cv-label">PAN No</td><td colspan="3">{{ $customer?->pan_number }}</td></tr>
        <tr><td class="cv-label">Mobile No.</td><td colspan="3">{{ $customer?->mobile_no }}</td></tr>
        <tr class="cv-gap"><td colspan="4"></td></tr>
        <tr>
            <td class="cv-label">Disbursal Amount</td>
            <td colspan="3">{{ filled($customer?->sanctioned_loan_amount) ? '₹'.indianNumberFormat($customer->sanctioned_loan_amount) : '' }}</td>
        </tr>
        <tr><td class="cv-label">Month / Year</td><td colspan="3">{{ $customer?->disbursal_date?->format('m/Y') }}</td></tr>
        <tr><td class="cv-label">Declaration</td><td colspan="3">{{ $voucher->type->label() }}</td></tr>
        <tr>
            <td class="cv-label">Total Amount to be paid</td>
            <td colspan="3">
                ₹{{ indianNumberFormat($voucher->effectiveAmount()) }}
                <div class="cv-muted">{{ indianAmountInWordsWithPaise($voucher->effectiveAmount()) }}</div>
                @if ($voucher->approved_amount !== null && (float) $voucher->approved_amount !== (float) $voucher->amount)
                    <div class="cv-muted">Asked for ₹{{ indianNumberFormat($voucher->amount) }} — amount set by the Admin.</div>
                @endif
            </td>
        </tr>
        @if ($voucher->declaresSubvention())
            <tr>
                <td class="cv-label">Subvention on the case</td>
                <td colspan="3">
                    ₹{{ indianNumberFormat($voucher->subvention_amount) }}
                    <div class="cv-muted">{{ indianAmountInWordsWithPaise($voucher->subvention_amount) }}</div>
                </td>
            </tr>
        @endif
        <tr class="cv-gap"><td colspan="4"></td></tr>
        <tr>
            <td class="cv-label">Payment Mode</td>
            <td colspan="3">
                <div class="cv-modes">
                    @foreach (CashbackPaymentMode::cases() as $option)
                        <span @class(['cv-mode', 'cv-mode--on' => $mode === $option])>{{ strtoupper($option->label()) }}</span>
                    @endforeach
                </div>
            </td>
        </tr>
        <tr>
            <td class="cv-label">UPI Platform (Gpay/Phonepe/Paytm)</td>
            <td colspan="3">{{ $voucher->upi_platform ? CashbackPaymentMode::upiPlatformLabel($voucher->upi_platform) : '' }}</td>
        </tr>
        <tr>
            <td class="cv-label">Customer UPI No Or UPI ID/Number</td>
            <td colspan="3">
                @if ($mode?->needsBankAccount())
                    A/c {{ $voucher->payee_account_no }} · IFSC {{ $voucher->payee_ifsc }}
                @else
                    {{ $voucher->payee_upi }}
                @endif
            </td>
        </tr>
        @foreach ($chain as $label => [$person, $signature])
            <tr>
                <td class="cv-label">{{ $label }}</td>
                <td>{{ $person?->emp_name }}</td>
                <td class="cv-sign">Signature</td>
                <td @class(['cv-sign-value', 'cv-tone--'.($signature['tone'] ?? 'signed')])>{{ $signature['text'] ?? '' }}</td>
            </tr>
        @endforeach
        <tr>
            <td class="cv-label">Approved By</td>
            <td>{{ $voucher->approver?->name }}</td>
            <td class="cv-sign">Signature</td>
            <td class="cv-sign-value">{{ $signed($voucher->approver, $voucher->approved_at) }}</td>
        </tr>
        <tr>
            <td class="cv-label">UTR/Transection ID<div class="cv-muted">(it is for payer use only)</div></td>
            <td colspan="3">
                {{ $voucher->transaction_id }}
                @if (blank($voucher->transaction_id) && $voucher->isApproved())
                    <span class="cv-to-fill">{{ $toFill }}</span>
                @endif
            </td>
        </tr>
        <tr>
            <td class="cv-label">Paid By</td>
            <td>
                {{ $voucher->paid_by_name ?? $voucher->payer?->name }}
                @if (! $voucher->isPaid() && $voucher->isApproved())
                    <span class="cv-to-fill">{{ $toFill }}</span>
                @endif
            </td>
            <td class="cv-sign">Signature</td>
            <td class="cv-sign-value">{{ $voucher->isPaid() && $voucher->payer ? 'Recorded by '.$voucher->payer->name.' · '.$voucher->payment_updated_at?->format('d/m/Y, h:i A') : '' }}</td>
        </tr>
        <tr>
            <td class="cv-label">Payment Date</td>
            <td colspan="3">
                {{ $date($voucher->payment_date) }}
                @if (blank($voucher->payment_date) && $voucher->isApproved())
                    <span class="cv-to-fill">{{ $toFill }}</span>
                @endif
            </td>
        </tr>
        <tr>
            <td class="cv-label">Status</td>
            <td colspan="3">
                <span class="cv-status cv-status--{{ $voucher->payment_status->value }}">{{ $voucher->payment_status->label() }}</span>
                <span class="cv-muted"> · {{ $voucher->status->label() }}</span>
                @if ($voucher->payment_remarks)
                    <div class="cv-muted">{{ $voucher->payment_remarks }}</div>
                @endif
            </td>
        </tr>
        @if ($voucher->return_status !== null && $voucher->return_status !== CashbackReturnStatus::Rejected)
            <tr>
                <td class="cv-label">Returned by Customer</td>
                <td colspan="3">
                    <span @class(['cv-returned' => $voucher->isReturned()])>₹{{ indianNumberFormat($voucher->returned_amount) }}{{ $voucher->isFullyReturned() ? ' — returned in full' : '' }}</span>
                    on {{ $date($voucher->return_date) }} · {{ $voucher->return_mode?->label() }}{{ $voucher->return_reference ? ' · UTR '.$voucher->return_reference : '' }}
                    <div class="cv-muted">{{ $voucher->return_reason }}</div>
                    <div class="cv-muted">Asked by {{ $voucher->returnRequester?->name ?? '—' }} · {{ $voucher->return_requested_at?->format('d/m/Y, h:i A') }}</div>
                </td>
            </tr>
            <tr>
                <td class="cv-label">Return Approved By</td>
                <td>{{ $voucher->isReturned() ? $voucher->returnReviewer?->name : '' }}</td>
                <td class="cv-sign">Signature</td>
                <td @class(['cv-sign-value', 'cv-tone--waiting' => $voucher->hasPendingReturn()])>
                    {{ $voucher->isReturned() ? 'Digitally signed · '.$voucher->return_reviewed_at?->format('d/m/Y, h:i A') : 'Awaiting the Admin\'s approval' }}
                </td>
            </tr>
            @if ($voucher->isReturned())
                <tr>
                    <td class="cv-label">Net Cashback</td>
                    <td colspan="3">₹{{ indianNumberFormat($voucher->netAmount()) }}</td>
                </tr>
            @endif
        @endif
    </table>
</div>
