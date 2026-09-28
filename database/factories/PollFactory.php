<?php

namespace Database\Factories;

use App\Models\Poll;
use App\Models\PollType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Poll>
 */
class PollFactory extends Factory
{
    protected $model = Poll::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'question' => fake()->sentence(8),
            'poll_type_id' => fn (): int => PollType::query()->value('id') ?? PollType::query()->create([
                'name' => 'Feedback',
                'options' => ['Good', 'Satisfactory', 'Bad'],
                'allow_comment' => true,
            ])->id,
            'options' => ['Good', 'Satisfactory', 'Bad'],
            'allow_comment' => true,
            'is_mandatory' => false,
            'is_anonymous' => false,
            'audience' => Poll::AUDIENCE_COMPANY,
            'is_active' => true,
            'expires_at' => now()->addDays(7),
            'created_by' => User::factory(),
        ];
    }

    public function mandatory(): static
    {
        return $this->state(fn (): array => ['is_mandatory' => true]);
    }

    public function anonymous(): static
    {
        return $this->state(fn (): array => ['is_anonymous' => true]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subHour()]);
    }
}
