<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);


        //create roles with this seeder
        //   $this->call([
        //         RolesSeeder::class,
        //         AdminUserSeeder::class
        //     ]);

        // $this->call([
        //     CitySeeder::class,
        // ]);

        //php artisan db:seed --class=CitySeeder
        //test 1
    }
}
