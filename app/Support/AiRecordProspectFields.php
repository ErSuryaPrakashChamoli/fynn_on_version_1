<?php

namespace App\Support;

use App\Models\AiCustomerRecord;
use App\Models\City;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Finds a lead's prospect details (name, mobile, PAN, ...) inside an AI
 * record's data JSON.
 *
 * Every upload template picks its own field keys, so a mobile number can sit
 * under "mobile", "mobile_no" or "contact" rather than the canonical
 * "mobile_number" the rest of the app asks for. A field is looked up by its
 * canonical key, then by a known synonym key, then by the template field's
 * type (mobile / pan / email), and last by the template field's label or
 * aliases.
 */
class AiRecordProspectFields
{
    /**
     * Canonical prospect field => its label, the template field type that
     * holds it (if any) and the other names it is commonly uploaded as.
     *
     * @var array<string, array{label: string, type: ?string, synonyms: list<string>}>
     */
    public const FIELDS = [
        'customer_name' => [
            'label' => 'Customer Name',
            'type' => null,
            'synonyms' => ['name', 'customer', 'applicant', 'applicant_name', 'full_name', 'client_name', 'borrower_name'],
        ],
        'mobile_number' => [
            'label' => 'Mobile Number',
            'type' => 'mobile',
            'synonyms' => ['mobile', 'mobile_no', 'mobile_num', 'phone', 'phone_no', 'phone_number', 'contact', 'contact_no', 'contact_number', 'cell', 'cell_no'],
        ],
        'pan_number' => [
            'label' => 'PAN Number',
            'type' => 'pan',
            'synonyms' => ['pan', 'pan_no', 'pan_card', 'pan_card_no', 'pan_card_number'],
        ],
        'email' => [
            'label' => 'Email Address',
            'type' => 'email',
            'synonyms' => ['email_id', 'email_address', 'mail', 'mail_id'],
        ],
        'current_location' => [
            'label' => 'Current Location',
            'type' => null,
            'synonyms' => ['location', 'city', 'current_city'],
        ],
        'job_location' => [
            'label' => 'Job Location',
            'type' => null,
            'synonyms' => ['office_location', 'work_location', 'job_city', 'office_city', 'company_location'],
        ],
        'residence_location' => [
            'label' => 'Residence Location',
            'type' => null,
            'synonyms' => ['residence', 'residence_city', 'residential_location', 'home_location', 'home_city'],
        ],
        'salary' => [
            'label' => 'Salary',
            'type' => null,
            'synonyms' => ['net_salary', 'monthly_salary', 'gross_salary', 'income', 'monthly_income', 'net_income', 'take_home'],
        ],
    ];

    /**
     * The cleaned value of $field, or null when the record does not hold one.
     * Locations only resolve to an active City name, since every location
     * dropdown is keyed by it and rejects anything else on save.
     */
    public static function value(AiCustomerRecord $record, string $field): ?string
    {
        // The canonical key wins outright, which also spares listings that
        // show display names a template lookup per row.
        $canonicalValue = self::clean($field, $record->data[$field] ?? null);

        if ($canonicalValue !== null) {
            return $canonicalValue;
        }

        foreach (self::candidateKeys($record, $field) as $key) {
            $value = self::clean($field, $record->data[$key] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The data key an edited $field should be written back to: the key its
     * current value was read from, else the template's own key for it,
     * else the canonical key.
     */
    public static function key(AiCustomerRecord $record, string $field): string
    {
        foreach (self::candidateKeys($record, $field) as $key) {
            if (self::clean($field, $record->data[$key] ?? null) !== null) {
                return $key;
            }
        }

        return self::candidateKeys($record, $field, includeLabelMatches: false)[0] ?? $field;
    }

    /**
     * Data keys that may hold $field on this record, best match first.
     *
     * @return list<string>
     */
    private static function candidateKeys(AiCustomerRecord $record, string $field, bool $includeLabelMatches = true): array
    {
        $definition = self::FIELDS[$field] ?? ['type' => null, 'synonyms' => []];
        $names = [$field, ...$definition['synonyms']];

        $templateFields = collect($record->schema?->getFieldDefinitions() ?? []);
        $dataKeys = array_map('strval', array_keys(is_array($record->data) ? $record->data : []));

        $byKey = $templateFields->pluck('key')
            ->map(fn (mixed $key): string => (string) $key)
            ->merge($dataKeys)
            ->unique()
            ->filter(fn (string $key): bool => in_array(self::normalize($key), $names, true))
            ->sortBy(fn (string $key): int => (int) array_search(self::normalize($key), $names, true));

        $byType = $definition['type'] === null
            ? collect()
            : $templateFields
                ->filter(fn (array $templateField): bool => Str::lower((string) ($templateField['type'] ?? '')) === $definition['type'])
                ->pluck('key');

        $byLabel = $includeLabelMatches
            ? $templateFields
                ->filter(fn (array $templateField): bool => self::templateFieldNames($templateField)
                    ->contains(fn (string $name): bool => in_array(self::normalize($name), $names, true)))
                ->pluck('key')
            : collect();

        return $byKey->merge($byType)
            ->merge($byLabel)
            ->map(fn (mixed $key): string => (string) $key)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * A template field's label and aliases. Aliases are saved either as a
     * list or as one comma-separated string ("Mobile, Mobile No, Phone").
     *
     * @param  array<string, mixed>  $templateField
     * @return Collection<int, string>
     */
    private static function templateFieldNames(array $templateField): Collection
    {
        $aliases = $templateField['aliases'] ?? [];

        return collect([$templateField['label'] ?? null, ...(is_array($aliases) ? $aliases : [$aliases])])
            ->filter(fn (mixed $name): bool => is_string($name))
            ->flatMap(fn (string $name): array => explode(',', $name))
            ->map(fn (string $name): string => trim($name))
            ->filter()
            ->values();
    }

    private static function clean(string $field, mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $value = match ($field) {
            'mobile_number' => self::cleanMobile($value),
            'pan_number' => Str::upper(str_replace(' ', '', $value)),
            'salary' => self::cleanAmount($value),
            'current_location', 'job_location', 'residence_location' => self::matchCity($value),
            default => $value,
        };

        return filled($value) ? $value : null;
    }

    /**
     * Digits only, trimmed to the last 10 so "+91 98765 43210" is stored the
     * same way as "9876543210".
     */
    private static function cleanMobile(string $value): string
    {
        $digits = (string) preg_replace('/\D+/', '', $value);

        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }

    /**
     * Whole rupees as plain digits: "₹ 40,00,000.00" becomes "4000000".
     */
    private static function cleanAmount(string $value): string
    {
        $number = (string) preg_replace('/[^0-9.]/', '', $value);

        return explode('.', $number)[0];
    }

    /**
     * The active City whose name matches, ignoring case. "Bangalore,
     * Karnataka" (the dropdown's own label format) also matches "Bangalore".
     */
    private static function matchCity(string $value): ?string
    {
        foreach (array_unique([$value, trim(Str::before($value, ','))]) as $candidate) {
            $city = City::query()
                ->where('is_active', 1)
                ->whereRaw('LOWER(city) = ?', [Str::lower($candidate)])
                ->value('city');

            if ($city !== null) {
                return $city;
            }
        }

        return null;
    }

    private static function normalize(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', Str::lower($value)), '_');
    }
}
