<?php

namespace Database\Factories\Training;

use App\Models\Tenant;
use App\Models\Training\TrainingCertificate;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingEnrollment;
use App\Models\User;
use App\Services\Training\CertificateService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingCertificate>
 */
class TrainingCertificateFactory extends Factory
{
    protected $model = TrainingCertificate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'training_enrollment_id' => TrainingEnrollment::factory(),
            'training_course_id' => TrainingCourse::factory(),
            'trainee_id' => User::factory(),
            'certificate_number' => CertificateService::NUMBER_PREFIX
                .'-'.now()->year.'-'
                .str_pad((string) fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'final_score' => fake()->numberBetween(70, 98),
            'issued_on' => now()->subDays(fake()->numberBetween(0, 30)),
        ];
    }
}
