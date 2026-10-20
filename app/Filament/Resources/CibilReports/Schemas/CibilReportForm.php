<?php

namespace App\Filament\Resources\CibilReports\Schemas;

use App\Enums\CibilRequestType;
use App\Enums\CreditBureau;
use App\Filament\Resources\CibilReports\CibilReportResource;
use App\Models\CibilReport;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\Lead;
use App\Services\Cibil\CibilReportService;
use App\Support\BureauCheckCandidates;
use App\Support\CibilAccess;
use App\Support\CibilSubject;
use Filament\Facades\Filament;
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
 * The fetch form: first choose the bureau (CIBIL or Experian), then pick the
 * lead (marked "Bureau Check") or the customer file the request is for,
 * which fills in what it already holds (its PAN cannot be changed), choose
 * what to request from that bureau, complete the applicant's details and
 * record the customer's consent. CreateCibilReport hands the result to
 * CibilReportService, which validates again, reuses a report the file
 * already has, or calls the bureau.
 */
class CibilReportForm
{
    public const string FOR_LEAD = 'lead';

    public const string FOR_CUSTOMER = 'customer';

    /** @var list<string> */
    private const APPLICANT_FIELDS = ['customer_name', 'pan_number', 'mobile_no', 'email', 'date_of_birth', 'gender', 'pincode'];

    /** @var array<string, string> */
    private const REQUEST_TYPE_ICONS = [
        'score' => 'heroicon-o-chart-bar',
        'full_report' => 'heroicon-o-document-text',
        'pdf' => 'heroicon-o-document-arrow-down',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Bureau')
                    ->description('Which credit bureau to ask. Each bureau bills separately, and a report from one never stands in for the other.')
                    ->schema([
                        CibilRequestFields::bureau()
                            ->hiddenLabel(),
                    ])
                    ->columnSpanFull(),

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
                            ->getSearchResultsUsing(fn (string $search): array => BureauCheckCandidates::searchLeads($search))
                            ->getOptionLabelUsing(fn (mixed $value): ?string => self::leadLabel($value))
                            ->noOptionsMessage('You have no open leads yet.')
                            ->noSearchResultsMessage('No open lead of yours matches. Search by name, mobile number or PAN.')
                            ->helperText('Leads marked "Bureau Check" are listed first, then your newest leads. Type to search any open lead in My Leads or Assigned Leads by name, mobile number or PAN.')
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

                Section::make('What to request')
                    ->description(fn (Get $get): string => 'Each request is billed by '.(self::bureau($get('bureau'))?->label() ?? 'the bureau').'. If the file already has a report from it that answers the request, that report is opened instead.')
                    ->schema([
                        ToggleButtons::make('request_type')
                            ->hiddenLabel()
                            ->options(fn (): array => self::requestTypeOptions())
                            ->icons(self::REQUEST_TYPE_ICONS)
                            ->default(fn (): ?string => self::defaultRequestType())
                            ->inline()
                            ->required()
                            ->validationMessages(['required' => 'Choose what to request.'])
                            ->live(),
                    ])
                    ->columnSpanFull(),

                Section::make('Applicant details')
                    ->description('Name, PAN, mobile number and gender are required by the bureau; the rest is kept for the record.')
                    ->schema([
                        CibilRequestFields::name(),

                        CibilRequestFields::pan(fn (Get $get): bool => self::subjectPan($get) !== null)
                            ->helperText(fn (Get $get): ?HtmlString => self::previousFetchNotice($get('pan_number'), $get('bureau'))),

                        CibilRequestFields::mobile(),

                        CibilRequestFields::gender(),

                        CibilRequestFields::dateOfBirth(),

                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255),

                        TextInput::make('pincode')
                            ->label('PIN code')
                            ->regex(CibilReportService::PINCODE_PATTERN)
                            ->validationMessages(['regex' => 'Enter a valid 6-digit PIN code.']),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Consent')
                    ->schema(CibilRequestFields::consent(
                        fn (Get $get): ?CibilRequestType => CibilRequestType::tryFrom((string) $get('request_type')),
                        fn (Get $get): ?CreditBureau => self::bureau($get('bureau')),
                    ))
                    ->columnSpanFull(),
            ]);
    }

    /**
     * What this login may request — the same from either bureau.
     *
     * @return array<string, string>
     */
    protected static function requestTypeOptions(): array
    {
        return collect(CibilAccess::requestableTypes(Filament::auth()->user()))
            ->mapWithKeys(fn (CibilRequestType $type): array => [$type->value => $type->label()])
            ->all();
    }

    protected static function bureau(mixed $value): ?CreditBureau
    {
        return $value instanceof CreditBureau ? $value : CreditBureau::tryFrom((string) $value);
    }

    protected static function defaultRequestType(): ?string
    {
        $options = self::requestTypeOptions();

        return array_key_exists(CibilRequestType::FullReport->value, $options)
            ? CibilRequestType::FullReport->value
            : array_key_first($options);
    }

    /** The PAN the picked lead or customer file holds, if any. */
    protected static function subjectPan(Get $get): ?string
    {
        $subject = self::subject($get('fetch_for'), $get('lead_key'), $get('customer_id'));

        return $subject === null ? null : CibilSubject::details($subject)['pan_number'];
    }

    public static function subject(mixed $fetchFor, mixed $leadKey, mixed $customerId): Lead|CustomerAssignment|Customer|null
    {
        return match ($fetchFor) {
            self::FOR_LEAD => BureauCheckCandidates::resolveLead($leadKey),
            self::FOR_CUSTOMER => BureauCheckCandidates::resolveCustomer($customerId),
            default => null,
        };
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

        self::fillFromSubject($lead, $set);
    }

    protected static function fillFromCustomer(mixed $customerId, Set $set): void
    {
        $customer = BureauCheckCandidates::resolveCustomer($customerId);

        if ($customer === null) {
            return;
        }

        self::fillFromSubject($customer, $set);
    }

    /**
     * What the file holds, plus gender and date of birth from its last
     * request (files do not keep those).
     */
    protected static function fillFromSubject(Lead|CustomerAssignment|Customer $subject, Set $set): void
    {
        foreach (CibilSubject::details($subject) as $field => $value) {
            $set($field, $value);
        }

        $previous = $subject->cibilReports()->latest('id')->first();

        $set('gender', $previous?->gender);
        $set('date_of_birth', $previous?->date_of_birth?->toDateString());
    }

    /**
     * Pulls cost money: tell the user when they can already see a report for
     * this PAN from the chosen bureau, so they open that one unless a fresh
     * pull is really needed.
     */
    protected static function previousFetchNotice(?string $pan, mixed $bureau): ?HtmlString
    {
        $pan = Str::upper(trim((string) $pan));
        $bureau = self::bureau($bureau) ?? CreditBureau::Cibil;

        if (! preg_match(CibilReportService::PAN_PATTERN, $pan)) {
            return null;
        }

        $latest = CibilReportResource::getEloquentQuery()
            ->where('pan_number', $pan)
            ->where('bureau', $bureau)
            ->latest('created_at')
            ->latest('id')
            ->first();

        if (! $latest instanceof CibilReport) {
            return null;
        }

        $score = $latest->score !== null ? " (score {$latest->score})" : '';

        return new HtmlString(sprintf(
            'Already fetched from %s on %s%s. <a href="%s" class="fi-link text-primary-600 underline">Open that report</a> unless a fresh pull is needed.',
            e($bureau->label()),
            e($latest->created_at?->format('d M Y, h:i A') ?? ''),
            e($score),
            e(CibilReportResource::getUrl('view', ['record' => $latest])),
        ));
    }
}
