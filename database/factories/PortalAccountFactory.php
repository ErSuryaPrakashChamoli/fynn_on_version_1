<?php

namespace Database\Factories;

use App\Enums\PortalRole;
use App\Models\PortalAccount;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PortalAccount>
 */
class PortalAccountFactory extends Factory
{
    protected $model = PortalAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'tenant_id' => Tenant::factory(),
            'portal' => PortalRole::Trainee->portal(),
            'portal_role' => PortalRole::Trainee,
            'is_active' => true,
            'expires_at' => null,
        ];
    }

    public function trainer(): static
    {
        return $this->state(fn (): array => [
            'portal' => PortalRole::Trainer->portal(),
            'portal_role' => PortalRole::Trainer,
        ]);
    }

    public function trainee(): static
    {
        return $this->state(fn (): array => [
            'portal' => PortalRole::Trainee->portal(),
            'portal_role' => PortalRole::Trainee,
        ]);
    }

    public function demo(): static
    {
        return $this->state(fn (): array => [
            'portal' => PortalRole::Demo->portal(),
            'portal_role' => PortalRole::Demo,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }
}
