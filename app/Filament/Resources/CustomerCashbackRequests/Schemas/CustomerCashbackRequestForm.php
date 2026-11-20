<?php

namespace App\Filament\Resources\CustomerCashbackRequests\Schemas;

use App\Enums\CashbackPaymentMode;
use App\Enums\CashbackPaymentStatus;
use App\Enums\CashbackType;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Customer;
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
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * The cashback form (the paper "Acknowledgment" voucher, made digital).
 * The customer's name, PAN, mobile, disbursal amount and month come from
 * the file, so only the cashback itself and how to pay it are asked for.
 * fields() is shared with the customer page's "Fill cashback form" modals.
 */
class CustomerCashbackRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Customer file')
                    ->icon(Heroicon::OutlinedFolderOpen)
                    ->description('Only files at the disbursal step or disbursed, with no cashback form in progress, are listed. The customer\'s details are taken from the file.')
                    ->schema([
                        Select::make('customer_id')
                            ->label('Customer')
                            ->required()
                            ->live()
                            ->getSearchResultsUsing(fn (string $search): array => self::customerSearch($search))
                            ->getOptionLabelUsing(fn ($value): ?string => self::customerLabel($value))
                            ->helperText('Search by name, mobile, PAN or application number.'),

                        Placeholder::make('customer_summary')
                            ->label('From the file')
                            ->visible(fn (Get $get): bool => filled($get('customer_id')))
                            ->content(fn (Get $get): HtmlString => self::summary($get('customer_id'))),
                    ])
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
     * Files the user may see (the customer listing's own scope) at the
     * disbursal step or disbursed, with no live cashback form.
     *
     * @return array<int, string>
     */
    protected static function customerSearch(string $search): array
    {
        return CustomerResource::getEloquentQuery()
            ->whereIn('journey_status', CustomerCashbackService::OPEN_JOURNEY_STATUSES)
            ->whereDoesntHave('cashbackRequests', fn ($query) => $query->live())
            ->where(fn ($query) => $query
                ->where('customer_name', 'like', "%{$search}%")
                ->orWhere('mobile_no', 'like', "%{$search}%")
                ->orWhere('pan_number', 'like', "%{$search}%")
                ->orWhere('application_no', 'like', "%{$search}%"))
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

    protected static function labelFor(Customer $customer): string
    {
        return trim($customer->customer_name.' · '.($customer->application_no ?: $customer->mobile_no));
    }

    protected static function summary(mixed $customerId): HtmlString
    {
        $customer = filled($customerId) ? CustomerResource::getEloquentQuery()->with('assignedTo')->find((int) $customerId) : null;

        if (! $customer) {
            return new HtmlString('—');
        }

        $rows = [
            'Name' => $customer->customer_name,
            'PAN' => $customer->pan_number,
            'Mobile' => $customer->mobile_no,
            'Disbursal amount' => filled($customer->sanctioned_loan_amount) ? '₹'.indianNumberFormat($customer->sanctioned_loan_amount) : 'Not disbursed yet',
            'Month / Year' => $customer->disbursal_date?->format('F Y') ?? '—',
            'ARO (file owner)' => $customer->assignedTo?->emp_name ?? '—',
        ];

        return new HtmlString(collect($rows)
            ->map(fn (?string $value, string $label): string => '<span class="text-gray-500 dark:text-gray-400">'.e($label).':</span> <span class="font-medium text-gray-950 dark:text-white">'.e((string) ($value ?: '—')).'</span>')
            ->implode(' &nbsp;·&nbsp; '));
    }
}
