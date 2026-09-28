<?php

namespace Database\Factories;

use App\Enums\ComplaintStatus;
use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\ComplaintPriority;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Complaint>
 */
class ComplaintFactory extends Factory
{
    protected $model = Complaint::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'raised_by' => User::factory(),
            'category_id' => fn (): int => ComplaintCategory::query()->value('id') ?? ComplaintCategory::query()->create([
                'name' => 'IT',
                'routing' => 'team',
                'handler_roles' => ['IT'],
            ])->id,
            'priority_id' => fn (): int => ComplaintPriority::query()->value('id') ?? ComplaintPriority::query()->create([
                'name' => 'Medium',
                'color' => 'info',
                'resolve_within_minutes' => 2880,
            ])->id,
            'subject' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'status' => ComplaintStatus::Open,
            'sla_started_at' => now(),
            'due_at' => now()->addHours(48),
        ];
    }

    public function overdue(): static
    {
        return $this->state(fn (): array => [
            'sla_started_at' => now()->subHours(72),
            'due_at' => now()->subHours(24),
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn (): array => [
            'status' => ComplaintStatus::Resolved,
            'resolved_at' => now(),
            'resolution_note' => 'Fixed.',
        ]);
    }
}
