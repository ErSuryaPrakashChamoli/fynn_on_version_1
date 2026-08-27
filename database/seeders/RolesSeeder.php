<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RolesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        $roles = [
            'Admin',
            'Manager',
            'Team Leader',
            'Employee',
            'Cluster Manager',
            'Business Head',
            'IT',
            'Caller',
            'MIS',
            'Accounts',
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate([
                'name' => $role,
                'guard_name' => 'web',
            ]);
        }

        //
    }
}
