<?php

namespace Database\Factories;

use App\Enums\CibilConsentMethod;
use App\Enums\CibilPdfStatus;
use App\Enums\CibilReportStatus;
use App\Enums\CibilRequestType;
use App\Enums\CreditBureau;
use App\Models\CibilReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CibilReport>
 */
class CibilReportFactory extends Factory
{
    protected $model = CibilReport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $score = fake()->numberBetween(300, 900);

        return [
            'requested_by' => User::factory(),
            'request_type' => CibilRequestType::FullReport,
            'bureau' => CreditBureau::Cibil,
            'customer_name' => fake()->name(),
            'pan_number' => Str::upper(fake()->lexify('?????')).fake()->numerify('####').Str::upper(fake()->randomLetter()),
            'mobile_no' => fake()->numerify('9#########'),
            'email' => fake()->safeEmail(),
            'date_of_birth' => fake()->dateTimeBetween('-60 years', '-21 years'),
            'gender' => fake()->randomElement(array_keys(CibilReport::GENDERS)),
            'pincode' => fake()->numerify('1#####'),
            'status' => CibilReportStatus::Success,
            'score' => $score,
            'bureau_reference' => 'REF-'.Str::upper(Str::random(8)),
            'provider_status_code' => 200,
            'response_payload' => ['score' => $score],
            'consent_given_at' => now(),
            'consent_purpose' => CibilRequestType::FullReport->consentPurpose(),
            'consent_method' => CibilConsentMethod::RecordedCall,
            'fetched_at' => now(),
            'completed_at' => now(),
        ];
    }

    public function experian(): static
    {
        return $this->state(fn (array $attributes): array => [
            'bureau' => CreditBureau::Experian,
            'consent_purpose' => self::type($attributes)->consentPurpose(CreditBureau::Experian),
        ]);
    }

    public function score(): static
    {
        return $this->state(fn (array $attributes): array => [
            'request_type' => CibilRequestType::Score,
            'consent_purpose' => CibilRequestType::Score->consentPurpose(self::bureau($attributes)),
        ]);
    }

    /** A PDF request whose file is saved at $path on the CIBIL PDF disk. */
    public function pdf(?string $path = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'request_type' => CibilRequestType::Pdf,
            'consent_purpose' => CibilRequestType::Pdf->consentPurpose(self::bureau($attributes)),
            'pdf_status' => $path !== null ? CibilPdfStatus::Available : CibilPdfStatus::Missing,
            'pdf_path' => $path,
        ]);
    }

    public function failed(string $reason = 'The bureau could not find a record for this PAN.'): static
    {
        return $this->state(fn (): array => [
            'status' => CibilReportStatus::Failed,
            'score' => null,
            'bureau_reference' => null,
            'response_payload' => null,
            'error_message' => $reason,
            'fetched_at' => null,
        ]);
    }

    public function uncertain(): static
    {
        return $this->state(fn (): array => [
            'status' => CibilReportStatus::Uncertain,
            'score' => null,
            'bureau_reference' => null,
            'provider_status_code' => null,
            'response_payload' => null,
            'error_message' => 'The request outcome is uncertain; the provider may have processed it. Verify its status before retrying.',
            'fetched_at' => null,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => CibilReportStatus::Pending,
            'score' => null,
            'bureau_reference' => null,
            'provider_status_code' => null,
            'response_payload' => null,
            'fetched_at' => null,
            'completed_at' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function type(array $attributes): CibilRequestType
    {
        $type = $attributes['request_type'] ?? CibilRequestType::FullReport;

        return $type instanceof CibilRequestType ? $type : CibilRequestType::from((string) $type);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function bureau(array $attributes): CreditBureau
    {
        $bureau = $attributes['bureau'] ?? CreditBureau::Cibil;

        return $bureau instanceof CreditBureau ? $bureau : CreditBureau::from((string) $bureau);
    }
}
