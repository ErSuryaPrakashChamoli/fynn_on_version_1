<?php

namespace Database\Seeders\Demo;

use App\Models\Demo\DemoUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The /demo login, written to demo_users on the demo database.
 *
 * Credentials come from config/demo.php (DEMO_USER_EMAIL /
 * DEMO_USER_PASSWORD). No password is kept in source: when
 * DEMO_USER_PASSWORD is empty a random one is generated and printed
 * once. Re-running with a password set updates it in place.
 */
class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        $configuredPassword = config('demo.user.password');
        $password = filled($configuredPassword) ? (string) $configuredPassword : Str::password(16);

        $user = DemoUser::query()->firstOrNew(['email' => config('demo.user.email')]);

        if ($user->exists && blank($configuredPassword)) {
            $this->command?->info("Demo login [{$user->email}] already exists — password left unchanged.");

            return;
        }

        $user->fill([
            'name' => config('demo.user.name'),
            'password' => $password,
            'is_active' => true,
        ])->save();

        $this->command?->info("Demo login ready: {$user->email}");

        if (blank($configuredPassword)) {
            $this->command?->warn("Generated password (shown once): {$password}");
        }
    }
}
