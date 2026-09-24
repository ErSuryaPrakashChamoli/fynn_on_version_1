<?php

namespace Database\Factories\Demo;

use App\Models\Demo\DemoUser;
use Database\Factories\UserFactory;

/**
 * A User row in the DEMO database (DemoUser is pinned to the demo
 * connection), with the same defaults as a main-database user.
 *
 * @extends UserFactory<DemoUser>
 */
class DemoUserFactory extends UserFactory
{
    protected $model = DemoUser::class;

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
