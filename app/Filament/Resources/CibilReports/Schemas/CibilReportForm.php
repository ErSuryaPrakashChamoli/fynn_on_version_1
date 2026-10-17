<?php

namespace App\Filament\Resources\CibilReports\Schemas;

use App\Filament\Resources\CibilReports\CibilReportResource;
use App\Models\CibilReport;
use App\Services\Cibil\CibilReportService;
use App\Support\BureauCheckCandidates;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * The fetch form: pick the lead (marked "Bureau Check") or the customer file
 * the report is for, which fills in what it already holds, complete the
 * applicant's details, confirm the customer's consent. CreateCibilReport
 * hands the result to CibilReportService, which validates again and calls
 * the bureau.
 */
class CibilReportForm
{
    public const string FOR_LEAD = 'lead';

    public const string FOR_CUSTOMER = 'customer';

    /** @var list<string> */
    private const APPLICANT_FIELDS = ['customer_name', 'pan_number', 'mobile_no', 'email', 'date_of_birth', 'gender', 'pincode'];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Fetch for')
                    ->description('A report is fetched for a lead or for a customer file.')
                    ->schema([
                        ToggleButtons::make('fetch_for')
                            ->hiddenLabel()
                            ->options([
                                self::FOR_LEAD => 'Lead',
                                self::FOR_CUSTOMER => 'Customer',
                            ])
                            ->icons([
                                self::FOR_LEAD => 'heroicon-o-user-plus',
                                self::FOR_CUSTOMER => 'heroicon-o-identification',
                            ])
                            ->inline()
                            ->required()
                            ->validationMessages(['required' => 'Choose whether the report is for a lead or a customer.'])
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('lead_key', null);
                                $set('customer_id', null);

                                foreach (self::APPLICANT_FIELDS as $field) {
                                    $set($field, null);
                                }
                            }),

                        Select::make('lead_key')
                            ->label('Lead')
                            ->placeholder('Search lead')
                            ->options(fn (): array => BureauCheckCandidates::leadOptions())
                            ->getOptionLabelUsing(fn (mixed $value): ?string => self::leadLabel($value))
                            ->noOptionsMessage('No lead is marked "Bureau Check" right now.')
                            ->helperText('Leads from My Leads and Assigned Leads whose status is "Bureau Check".')
                            ->visible(fn (Get $get): bool => $get('fetch_for') === self::FOR_LEAD)
                            ->required(fn (Get $get): bool => $get('fetch_for') === self::FOR_LEAD)
                            ->live()
                            ->afterStateUpdated(function (mixed $state, Set $set): void {
                                self::fillFromLead($state, $set);
                            }),

                        Select::make('customer_id')
                            ->label('Customer')
                            ->placeholder('Search customer')
                            ->options(fn (): array => BureauCheckCandidates::pendingCustomerOptions())
                            ->getSearchResultsUsing(fn (string $search): array => BureauCheckCandidates::searchCustomers($search))
                            ->getOptionLabelUsing(fn (mixed $value): ?string => self::customerLabel($value))
                            ->noOptionsMessage('Every customer file already has a CIBIL report. Type to search them.')
                            ->helperText('Customers without a CIBIL report are listed. Type to search all your customers by name, mobile, PAN or application number.')
                            ->visible(fn (Get $get): bool => $get('fetch_for') === self::FOR_CUSTOMER)
                            ->required(fn (Get $get): bool => $get('fetch_for') === self::FOR_CUSTOMER)
                            ->live()
                            ->afterStateUpdated(function (mixed $state, Set $set): void {
                                self::fillFromCustomer($state, $set);
                            }),
                    ])
                    ->columnSpanFull(),

                Section::make('Applicant details')
                    ->description('Name, PAN and date of birth are required; the rest is optional. These are sent to the bureau exactly as entered.')
                    ->schema([
                        TextInput::make('customer_name')
                            ->label('Full name (as on PAN)')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('pan_number')
                            ->label('PAN')
                            ->required()
                            ->length(10)
                            ->regex(CibilReportService::PAN_PATTERN)
                            ->validationMessages(['regex' => 'Enter a valid 10-character PAN, e.g. ABCDE1234F.'])
                            ->placeholder('ABCDE1234F')
                            ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (?string $state, Set $set): mixed => $set('pan_number', Str::upper(trim((string) $state))))
                            ->helperText(fn (Get $get): ?HtmlString => self::previousFetchNotice($get('pan_number'))),

                        DatePicker::make('date_of_birth')
                            ->label('Date of birth')
                            ->required()
                            ->maxDate(now()->subDay()),

                        TextInput::make('mobile_no')
                            ->label('Mobile number')
                            ->tel()
                            ->prefix('+91')
                            ->regex(CibilReportService::MOBILE_PATTERN)
                            ->validationMessages(['regex' => 'Enter a valid 10-digit mobile number.']),

                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255),

                        Select::make('gender')
                            ->label('Gender')
                            ->options(CibilReport::GENDERS)
                            ->searchable(false),

                        TextInput::make('pincode')
                            ->label('PIN code')
                            ->regex(CibilReportService::PINCODE_PATTERN)
                            ->validationMessages(['regex' => 'Enter a valid 6-digit PIN code.']),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Consent')
                    ->schema([
                        Checkbox::make('consent')
                            ->label('The customer has given consent for their CIBIL report to be fetched.')
                            ->helperText('A credit report may only be pulled with the customer\'s consent. Your name and the time are recorded with the report.')
                            ->accepted()
                            ->validationMessages(['accepted' => 'The customer\'s consent is required before a CIBIL report can be fetched.']),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    protected static function leadLabel(mixed $key): ?string
    {
        $lead = BureauCheckCandidates::resolveLead($key);

        return $lead === null ? null : BureauCheckCandidates::leadLabel($lead);
    }

    protected static function customerLabel(mixed $value): ?string
    {
        $customer = BureauCheckCandidates::resolveCustomer($value);

        return $customer === null ? null : BureauCheckCandidates::customerLabel($customer);
    }

    protected static function fillFromLead(mixed $key, Set $set): void
    {
        $lead = BureauCheckCandidates::resolveLead($key);

        if ($lead === null) {
            return;
        }

        foreach (BureauCheckCandidates::leadDetails($lead) as $field => $value) {
            $set($field, $value);
        }
    }

    protected static function fillFromCustomer(mixed $customerId, Set $set): void
    {
        $customer = BureauCheckCandidates::resolveCustomer($customerId);

        if ($customer === null) {
            return;
        }

        $set('customer_name', $customer->customer_name);
        $set('pan_number', Str::upper((string) $customer->pan_number));
        $set('mobile_no', $customer->mobile_no);
        $set('email', $customer->email);
    }

    /**
     * Pulls cost money: tell the user when they can already see a report for
     * this PAN, so they open that one unless a fresh pull is really needed.
     */
    protected static function previousFetchNotice(?string $pan): ?HtmlString
    {
        $pan = Str::upper(trim((string) $pan));

        if (! preg_match(CibilReportService::PAN_PATTERN, $pan)) {
            return null;
        }

        $latest = CibilReportResource::getEloquentQuery()
            ->where('pan_number', $pan)
            ->latest('created_at')
            ->latest('id')
            ->first();

        if (! $latest instanceof CibilReport) {
            return null;
        }

        $score = $latest->score !== null ? " (score {$latest->score})" : '';

        return new HtmlString(sprintf(
            'Already fetched on %s%s. <a href="%s" class="fi-link text-primary-600 underline">Open that report</a> unless a fresh pull is needed.',
            e($latest->created_at?->format('d M Y, h:i A') ?? ''),
            e($score),
            e(CibilReportResource::getUrl('view', ['record' => $latest])),
        ));
    }
}
