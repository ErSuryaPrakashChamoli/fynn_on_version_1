<?php

namespace App\Filament\Resources\CibilReports\Schemas;

use App\Enums\CibilConsentMethod;
use App\Enums\CibilRequestType;
use App\Enums\CreditBureau;
use App\Models\CibilReport;
use App\Services\Cibil\CibilReportService;
use Closure;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;

/**
 * The fields every bureau request asks for — the fetch form, the CIBIL
 * profile on a lead or customer file, and "Refresh report" all use these,
 * so the rules match what CibilReportService checks again server-side.
 * Surepass needs name, PAN, mobile and gender; consent is recorded with how
 * it was given, never as a bare tick.
 */
final class CibilRequestFields
{
    /**
     * Which bureau to ask: the first choice on every new request. Both are
     * always offered; CIBIL is preselected.
     */
    public static function bureau(): ToggleButtons
    {
        return ToggleButtons::make('bureau')
            ->label('Bureau')
            ->options(CreditBureau::options())
            ->icons(collect(CreditBureau::cases())->mapWithKeys(fn (CreditBureau $bureau): array => [$bureau->value => $bureau->icon()])->all())
            ->inline()
            ->required()
            ->default(CreditBureau::Cibil->value)
            ->live()
            ->validationMessages(['required' => 'Choose the bureau to request from.']);
    }

    public static function name(): TextInput
    {
        return TextInput::make('customer_name')
            ->label('Full name (as on PAN)')
            ->required()
            ->maxLength(255);
    }

    /** $locked: the file already holds the PAN, so it is shown but cannot be changed. */
    public static function pan(bool|Closure $locked = false): TextInput
    {
        return TextInput::make('pan_number')
            ->label('PAN')
            ->required()
            ->length(10)
            ->regex(CibilReportService::PAN_PATTERN)
            ->validationMessages(['regex' => 'Enter a valid 10-character PAN, e.g. ABCDE1234F.'])
            ->placeholder('ABCDE1234F')
            ->extraInputAttributes(['style' => 'text-transform: uppercase'])
            ->disabled($locked)
            ->dehydrated()
            ->helperText(fn (TextInput $component): ?string => $component->isDisabled() ? 'Taken from the file — a report can only be requested for its own applicant.' : null)
            ->live(onBlur: true)
            ->afterStateUpdated(fn (?string $state, Set $set): mixed => $set('pan_number', Str::upper(trim((string) $state))));
    }

    public static function mobile(): TextInput
    {
        return TextInput::make('mobile_no')
            ->label('Mobile number')
            ->tel()
            ->prefix('+91')
            ->required()
            ->regex(CibilReportService::MOBILE_PATTERN)
            ->validationMessages(['regex' => 'Enter a valid 10-digit mobile number.']);
    }

    /**
     * CIBIL matches on gender, Experian does not take it. $bureau: the
     * bureau, or null to read the form's own "bureau" field.
     */
    public static function gender(?CreditBureau $bureau = null): Select
    {
        $isCibil = fn (Get $get): bool => ($bureau ?? CreditBureau::tryFrom((string) $get('bureau')) ?? CreditBureau::Cibil) === CreditBureau::Cibil;

        return Select::make('gender')
            ->label(fn (Get $get): string => $isCibil($get) ? 'Gender' : 'Gender (optional)')
            ->options(CibilReport::GENDERS)
            ->searchable(false)
            ->required($isCibil)
            ->helperText(fn (Get $get): string => $isCibil($get)
                ? 'Not kept on leads or customer files; CIBIL needs it.'
                : 'Experian does not need it; kept for the record only.');
    }

    public static function dateOfBirth(): DatePicker
    {
        return DatePicker::make('date_of_birth')
            ->label('Date of birth (optional)')
            ->maxDate(now()->subDay());
    }

    /**
     * $type / $bureau: the request type and bureau, or closures taking Get
     * that resolve them. The purpose the customer consents to names both.
     *
     * @return list<Select|TextInput|Checkbox>
     */
    public static function consent(CibilRequestType|Closure|null $type = null, CreditBureau|Closure|null $bureau = null): array
    {
        return [
            Select::make('consent_method')
                ->label('How did the customer give consent?')
                ->options(CibilConsentMethod::options())
                ->searchable(false)
                ->required()
                ->validationMessages(['required' => 'Record how the customer gave their consent.']),

            TextInput::make('consent_reference')
                ->label('Consent reference (optional)')
                ->helperText('Call recording ID, form number, email subject — whatever lets someone find the evidence later.')
                ->maxLength(255),

            Checkbox::make('consent')
                ->label(function (Get $get) use ($type, $bureau): string {
                    $type = $type instanceof Closure ? $type($get) : $type;
                    $bureau = $bureau instanceof Closure ? $bureau($get) : $bureau;

                    return 'The customer has given consent for this enquiry'
                        .($type instanceof CibilRequestType ? ': '.$type->consentPurpose($bureau ?? CreditBureau::Cibil) : '')
                        .'.';
                })
                ->helperText('Your name, the time, the purpose and how consent was given are recorded with the request.')
                ->accepted()
                ->validationMessages(['accepted' => 'Customer consent is required before a credit bureau request can be made.']),
        ];
    }

    public static function freshReason(): Textarea
    {
        return Textarea::make('reason')
            ->label('Why is a fresh pull needed?')
            ->helperText('Recorded with the request. A fresh pull is billed even though a report is on file.')
            ->required()
            ->minLength(5)
            ->maxLength(400)
            ->rows(2);
    }

    /** Shown when an earlier request for this PAN ended with an uncertain outcome. */
    public static function afterUncertain(Closure $visible): Checkbox
    {
        return Checkbox::make('after_uncertain')
            ->label('I have verified the earlier request whose outcome was uncertain, and a new (billed) request is still needed.')
            ->visible($visible)
            ->accepted()
            ->validationMessages(['accepted' => 'The request outcome is uncertain; verify its status before retrying.']);
    }

    /**
     * @param  list<CibilRequestType>  $types
     */
    public static function requestType(array $types): Select
    {
        return Select::make('request_type')
            ->label('What to request')
            ->options(collect($types)->mapWithKeys(fn (CibilRequestType $type): array => [$type->value => $type->label()])->all())
            ->searchable(false)
            ->required()
            ->live();
    }
}
