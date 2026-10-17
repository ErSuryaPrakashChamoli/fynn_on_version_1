<?php

namespace Database\Factories;

use App\Enums\CibilReportStatus;
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
            'response_payload' => ['score' => $score],
            'consent_given_at' => now(),
            'fetched_at' => now(),
        ];
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
}
