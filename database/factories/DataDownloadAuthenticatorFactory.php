<?php

namespace Database\Factories;

use App\Models\DataDownloadAuthenticator;
use Illuminate\Database\Eloquent\Factories\Factory;
use PragmaRX\Google2FA\Google2FA;

/**
 * @extends Factory<DataDownloadAuthenticator>
 */
class DataDownloadAuthenticatorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_name' => fake()->name(),
            'secret' => (new Google2FA)->generateSecretKey(32),
            'configured_at' => now(),
        ];
    }
}
