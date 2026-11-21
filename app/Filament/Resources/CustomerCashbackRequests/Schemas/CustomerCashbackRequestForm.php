<?php

namespace App\Filament\Resources\CustomerCashbackRequests\Schemas;

use App\Enums\CashbackPaymentMode;
use App\Enums\CashbackPaymentStatus;
use App\Enums\CashbackType;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Customer;
use App\Models\CustomerCashbackRequest;
use App\Services\CustomerCashbackService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * The cashback form (the paper "Acknowledgment" voucher, made digital).
 * Opened on its own (Cashback Forms → Fill cashback form, or from the
 * Customers list), the user picks the case — any case at the disbursal
 * step or punched in on an earlier day or month, narrowed by disbursal
 * month if wanted. The customer's name, PAN, mobile, disbursal amount and
 * month come from the case, so only the cashback itself and how to pay it
 * are asked for. fields() is shared with the customer page's modals.
 */
class CustomerCashbackRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Which case?')
                    ->icon(Heroicon::OutlinedFolderOpen)
                    ->description('Any case of yours at the disbursal step or already disbursed — today or on an earlier day or month. A case whose form is still waiting for approval is not listed until that form is approved or rejected. A new form adds to the cashback the case already carries.')
                    ->schema([
                        Select::make('disbursal_month')
                            ->label('Disbursed in')
                            ->placeholder('Any time')
                            ->options(self::periodOptions())
                            ->searchable(false)
                            ->live()
                            ->dehydrated(false)
                            ->afterStateUpdated(fn (Set $set): mixed => $set('customer_id', null)),

                        Select::make('customer_id')
                            ->label('Case')
                            ->required()
                            ->live()
                            ->options(fn (Get $get): array => self::caseOptions($get('disbursal_month')))
                            ->getSearchResultsUsing(fn (string $search, Get $get): array => self::customerSearch($search, $get('disbursal_month')))
                            ->getOptionLabelUsing(fn ($value): ?string => self::customerLabel($value))
                            ->helperText('Pick from the list or search by name, mobile, PAN, application or LAN number.')
                            ->columnSpan(2),

                        Placeholder::make('customer_summary')
                            ->label('From the case')
                            ->visible(fn (Get $get): bool => filled($get('customer_id')))
                            ->content(fn (Get $get): HtmlString => self::summary($get('customer_id')))
                            ->columnSpanFull(),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),

                Section::make('Cashback')
                    ->icon(Heroicon::OutlinedReceiptRefund)
                    ->visible(fn (Get $get): bool => filled($get('customer_id')))
                    ->schema(self::fields())
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * The cashback and how the customer is to be paid. With $fixedType
     * (the disbursal step's declaration) the type is not asked again.
     *
     * @return array<int, mixed>
     */
    public static function fields(?CashbackType $fixedType = null): array
    {
        return [
            Radio::make('type')
                ->label('Declaration')
                ->options(CashbackType::formOptions())
                ->descriptions([
                    CashbackType::Cashback->value => 'Cashback promised to the customer on this case.',
                    CashbackType::Expected->value => 'Held against the case in case it is needed — the Team Leader or Manager can retrieve it later.',
                ])
                ->required()
                ->live()
                ->visible($fixedType === null)
                ->columnSpanFull(),

            TextInput::make('amount')
                ->label('Total amount to be paid')
                ->indianAmount(min: 1)
                ->required()
                ->columnSpanFull(),

            ...self::payeeFields($fixedType !== null
                ? $fixedType === CashbackType::Cashback
                : fn (Get $get): bool => $get('type') === CashbackType::Cashback->value),

            Textarea::make('remarks')
                ->label('Remarks')
                ->rows(2)
                ->maxLength(1000)
                ->columnSpanFull(),
        ];
    }

    /**
     * A revision of a paid cashback: the extra amount, the reason, and how
     * this second payment goes (pre-filled from the original).
     *
     * @return array<int, mixed>
     */
    public static function revisionFields(?CashbackType $type): array
    {
        return [
            TextInput::make('amount')
                ->label('Extra amount to be paid')
                ->indianAmount(min: 1)
                ->required()
                ->columnSpanFull(),

            ...self::payeeFields($type === CashbackType::Cashback),

            Textarea::make('remarks')
                ->label('Why does it have to be paid again?')
                ->rows(2)
                ->maxLength(1000)
                ->required()
                ->columnSpanFull(),
        ];
    }

    /**
     * Payment mode and where the money goes. UPI asks for the app and the
     * UPI ID / number; NEFT / IMPS for account number and IFSC.
     *
     * @return array<int, mixed>
     */
    public static function payeeFields(bool|\Closure $modeRequired = false): array
    {
        $mode = fn (Get $get): ?CashbackPaymentMode => CashbackPaymentMode::tryFrom((string) $get('payment_mode'));

        return [
            ToggleButtons::make('payment_mode')
                ->label('Payment mode')
                ->options(CashbackPaymentMode::options())
                ->inline()
                ->live()
                ->required($modeRequired)
                ->columnSpanFull(),

            Select::make('upi_platform')
                ->label('UPI platform')
                ->options(CashbackPaymentMode::UPI_PLATFORMS)
                ->searchable(false)
                ->visible(fn (Get $get): bool => $mode($get) === CashbackPaymentMode::Upi)
                ->required(fn (Get $get): bool => $mode($get) === CashbackPaymentMode::Upi),

            TextInput::make('payee_upi')
                ->label('Customer UPI ID / UPI number')
                ->maxLength(100)
                ->visible(fn (Get $get): bool => $mode($get) === CashbackPaymentMode::Upi)
                ->required(fn (Get $get): bool => $mode($get) === CashbackPaymentMode::Upi),

            TextInput::make('payee_account_no')
                ->label('Customer bank account number')
                ->maxLength(20)
                ->regex('/^\d{6,20}$/')
                ->visible(fn (Get $get): bool => (bool) $mode($get)?->needsBankAccount())
                ->required(fn (Get $get): bool => (bool) $mode($get)?->needsBankAccount()),

            TextInput::make('payee_ifsc')
                ->label('IFSC')
                ->maxLength(11)
                ->regex('/^[A-Za-z]{4}0[A-Za-z0-9]{6}$/')
                ->visible(fn (Get $get): bool => (bool) $mode($get)?->needsBankAccount())
                ->required(fn (Get $get): bool => (bool) $mode($get)?->needsBankAccount()),
        ];
    }

    /**
     * The Admin / Accounts payment update: status, payee details, UTR,
     * payment date and remarks.
     *
     * @return array<int, mixed>
     */
    public static function paymentFields(): array
    {
        $isPaid = fn (Get $get): bool => $get('payment_status') === CashbackPaymentStatus::Paid->value;

        return [
            ToggleButtons::make('payment_status')
                ->label('Payment status')
                ->options(CashbackPaymentStatus::options())
                ->colors(collect(CashbackPaymentStatus::cases())->mapWithKeys(fn (CashbackPaymentStatus $status): array => [$status->value => $status->color()])->all())
                ->inline()
                ->live()
                ->required()
                ->columnSpanFull(),

            ...self::payeeFields($isPaid),

            Grid::make(2)
                ->schema([
                    TextInput::make('transaction_id')
                        ->label('UTR / Transaction ID')
                        ->maxLength(100)
                        ->required(fn (Get $get): bool => $isPaid($get) && (bool) CashbackPaymentMode::tryFrom((string) $get('payment_mode'))?->needsTransactionId()),

                    DatePicker::make('payment_date')
                        ->label('Payment date')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->maxDate(today())
                        ->required($isPaid),

                    TextInput::make('paid_by_name')
                        ->label('Paid by')
                        ->helperText('Who made the payment.')
                        ->maxLength(150)
                        ->visible($isPaid)
                        ->required($isPaid),
                ])
                ->columnSpanFull(),

            Textarea::make('payment_remarks')
                ->label('Remarks / reason')
                ->rows(2)
                ->maxLength(1000)
                ->required(fn (Get $get): bool => $get('payment_status') === CashbackPaymentStatus::OnHold->value)
                ->helperText('Required when the payment is put on hold.')
                ->columnSpanFull(),
        ];
    }

    /**
     * A paid cashback the customer gave back: how much (up to what was
     * paid), how and when it came back, and why.
     *
     * @return array<int, mixed>
     */
    public static function returnFields(?float $paidAmount): array
    {
        $mode = fn (Get $get): ?CashbackPaymentMode => CashbackPaymentMode::tryFrom((string) $get('return_mode'));

        return [
            TextInput::make('returned_amount')
                ->label('Amount returned by the customer')
                ->indianAmount(min: 1, max: $paidAmount)
                ->required()
                ->helperText($paidAmount !== null ? 'Up to ₹'.indianNumberFormat($paidAmount).' — the amount paid on this form. Less means part of it was returned.' : null)
                ->columnSpanFull(),

            ToggleButtons::make('return_mode')
                ->label('Returned through')
                ->options(CashbackPaymentMode::options())
                ->inline()
                ->live()
                ->required()
                ->columnSpanFull(),

            Grid::make(2)
                ->schema([
                    TextInput::make('return_reference')
                        ->label('UTR / Transaction ID of the return')
                        ->maxLength(100)
                        ->required(fn (Get $get): bool => (bool) $mode($get)?->needsTransactionId()),

                    DatePicker::make('return_date')
                        ->label('Returned on')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->maxDate(today())
                        ->required(),
                ])
                ->columnSpanFull(),

            Textarea::make('return_reason')
                ->label('Why was it returned?')
                ->rows(2)
                ->maxLength(1000)
                ->required()
                ->columnSpanFull(),
        ];
    }

    /** Value of the "Disbursed in" filter for cases not disbursed yet. */
    public const AT_DISBURSAL_STEP = 'at_disbursal_step';

    /**
     * "Disbursed in" choices: the cases still at Step 4, then the last 24
     * months, newest first.
     *
     * @return array<string, string>
     */
    public static function periodOptions(): array
    {
        $months = collect(range(0, 23))
            ->mapWithKeys(fn (int $back): array => [
                now()->startOfMonth()->subMonths($back)->format('Y-m') => now()->startOfMonth()->subMonths($back)->format('F Y'),
            ])
            ->all();

        return [self::AT_DISBURSAL_STEP => 'Not disbursed yet (at disbursal step)', ...$months];
    }

    /**
     * Cases the user may see (the customer listing's own scope) at the
     * disbursal step or disbursed on any day, with no form waiting for
     * approval, optionally only those disbursed in one month.
     */
    protected static function caseQuery(?string $period): Builder
    {
        $query = CustomerResource::getEloquentQuery()
            ->whereIn('journey_status', CustomerCashbackService::OPEN_JOURNEY_STATUSES)
            ->whereDoesntHave('cashbackRequests', fn ($query) => $query->pending());

        if ($period === self::AT_DISBURSAL_STEP) {
            return $query->where('journey_status', 'approved');
        }

        if (filled($period) && preg_match('/^\d{4}-\d{2}$/', (string) $period)) {
            $month = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfMonth();

            return $query->whereBetween('disbursal_date', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()]);
        }

        return $query;
    }

    /**
     * The list shown before typing: the chosen month's cases, or the most
     * recently disbursed ones.
     *
     * @return array<int, string>
     */
    protected static function caseOptions(?string $period): array
    {
        return self::caseQuery($period)
            ->orderByDesc('disbursal_date')
            ->orderByDesc('id')
            ->limit(filled($period) ? 300 : 50)
            ->get()
            ->mapWithKeys(fn (Customer $customer): array => [$customer->id => self::labelFor($customer)])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected static function customerSearch(string $search, ?string $period = null): array
    {
        return self::caseQuery($period)
            ->where(fn ($query) => $query
                ->where('customer_name', 'like', "%{$search}%")
                ->orWhere('mobile_no', 'like', "%{$search}%")
                ->orWhere('pan_number', 'like', "%{$search}%")
                ->orWhere('application_no', 'like', "%{$search}%")
                ->orWhere('lan_no', 'like', "%{$search}%"))
            ->orderByDesc('disbursal_date')
            ->orderBy('customer_name')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Customer $customer): array => [$customer->id => self::labelFor($customer)])
            ->all();
    }

    protected static function customerLabel(mixed $value): ?string
    {
        $customer = filled($value) ? CustomerResource::getEloquentQuery()->find((int) $value) : null;

        return $customer ? self::labelFor($customer) : null;
    }

    /** "Ravi Sharma · FA2610150001 · disbursed 15 Oct 2026 · ₹5,00,000 · cashback ₹2,000" */
    protected static function labelFor(Customer $customer): string
    {
        return collect([
            $customer->customer_name,
            $customer->application_no ?: $customer->mobile_no,
            $customer->disbursal_date ? 'disbursed '.$customer->disbursal_date->format('d M Y') : 'not disbursed yet',
            filled($customer->sanctioned_loan_amount) ? '₹'.indianNumberFormat($customer->sanctioned_loan_amount) : null,
            (float) ($customer->cashback ?? 0) > 0 ? 'cashback ₹'.indianNumberFormat($customer->cashback) : null,
        ])->filter()->implode(' · ');
    }

    protected static function summary(mixed $customerId): HtmlString
    {
        $customer = filled($customerId)
            ? CustomerResource::getEloquentQuery()->with(['assignedTo', 'cashbackRequests'])->find((int) $customerId)
            : null;

        if (! $customer) {
            return new HtmlString('—');
        }

        $forms = $customer->cashbackRequests
            ->sortBy('id')
            ->map(fn (CustomerCashbackRequest $form): string => $form->voucher_no.' '.$form->type->label().' ₹'.indianNumberFormat($form->effectiveAmount()).' ('.strtolower($form->status->label()).')')
            ->implode(', ');

        $rows = [
            'Name' => $customer->customer_name,
            'PAN' => $customer->pan_number,
            'Mobile' => $customer->mobile_no,
            'Disbursal amount' => filled($customer->sanctioned_loan_amount) ? '₹'.indianNumberFormat($customer->sanctioned_loan_amount) : 'Not disbursed yet',
            'Month / Year' => $customer->disbursal_date?->format('F Y') ?? '—',
            'ARO (file owner)' => $customer->assignedTo?->emp_name ?? '—',
            'Cashback already on the case' => '₹'.indianNumberFormat($customer->cashback ?? 0).' — this form adds to it',
            'Earlier forms' => $forms ?: 'None',
        ];

        return new HtmlString(collect($rows)
            ->map(fn (?string $value, string $label): string => '<span class="text-gray-500 dark:text-gray-400">'.e($label).':</span> <span class="font-medium text-gray-950 dark:text-white">'.e((string) ($value ?: '—')).'</span>')
            ->implode(' &nbsp;·&nbsp; '));
    }
}
