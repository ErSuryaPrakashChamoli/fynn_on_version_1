<?php

namespace Database\Factories\Training;

use App\Models\Training\TrainingAttendance;
use App\Models\Training\TrainingBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingAttendance>
 */
class TrainingAttendanceFactory extends Factory
{
    protected $model = TrainingAttendance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'training_batch_id' => TrainingBatch::factory(),
            'training_session_id' => null,
            'trainee_id' => User::factory(),
            'attendance_date' => fake()->dateTimeBetween('-20 days', 'now'),
            'status' => fake()->randomElement(['present', 'present', 'present', 'late', 'absent']),
            'remarks' => null,
        ];
    }
}
