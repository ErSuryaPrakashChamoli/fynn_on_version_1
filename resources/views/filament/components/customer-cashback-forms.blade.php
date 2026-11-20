@php
    use App\Filament\Resources\CustomerCashbackRequests\CustomerCashbackRequestResource;

    /** @var \Illuminate\Support\Collection<int, \App\Models\CustomerCashbackRequest> $requests */
@endphp

<div class="space-y-3">
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
                    <x-filament::badge :color="$request->status->color()" size="sm">{{ $request->status->label() }}</x-filament::badge>
                    @if ($request->isApproved())
                        <x-filament::badge :color="$request->payment_status->color()" size="sm">{{ $request->payment_status->label() }}</x-filament::badge>
                    @endif
                </div>

                @foreach (array_filter([
                    $request->rejection_reason ? 'Rejected: '.$request->rejection_reason : null,
                    $request->retrieval_reason ? 'Retrieved: '.$request->retrieval_reason : null,
                    $request->payment_remarks ? 'Payment: '.$request->payment_remarks : null,
                ]) as $line)
                    <p class="mt-1 whitespace-pre-line text-gray-700 dark:text-gray-200">{{ $line }}</p>
                @endforeach

                <p class="fynn-list-meta mt-1">
                    Filled by {{ $request->requester?->name ?? 'Former user' }} · {{ $request->created_at?->format('d M Y, h:i A') }}
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
