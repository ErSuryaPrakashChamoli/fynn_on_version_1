<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //

        $user = User::firstOrCreate(
            [
                'email' => 'admin@fynnedge.com',
            ],
            [
                'name' => 'Admin',
                'password' => Hash::make('admin'),
            ]
        );

        // Assign existing Admin role
        $user->assignRole('Admin');
    }
}
