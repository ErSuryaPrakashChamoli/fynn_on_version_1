<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class CitySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //

        $now = Carbon::now();

        $cities = [
            // Maharashtra
            ['country' => 'India', 'state' => 'Maharashtra', 'city' => 'Mumbai', 'state_code' => 'MH', 'city_code' => 'MUM', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['country' => 'India', 'state' => 'Maharashtra', 'city' => 'Pune', 'state_code' => 'MH', 'city_code' => 'PUN', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],

            // Delhi
            ['country' => 'India', 'state' => 'Delhi', 'city' => 'New Delhi', 'state_code' => 'DL', 'city_code' => 'DEL', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],

            // Uttar Pradesh
            ['country' => 'India', 'state' => 'Uttar Pradesh', 'city' => 'Noida', 'state_code' => 'UP', 'city_code' => 'NOI', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['country' => 'India', 'state' => 'Uttar Pradesh', 'city' => 'Greater Noida', 'state_code' => 'UP', 'city_code' => 'GN', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['country' => 'India', 'state' => 'Uttar Pradesh', 'city' => 'Lucknow', 'state_code' => 'UP', 'city_code' => 'LKO', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],

            // Uttarakhand
            ['country' => 'India', 'state' => 'Uttarakhand', 'city' => 'Dehradun', 'state_code' => 'UK', 'city_code' => 'DDN', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],

            // Karnataka
            ['country' => 'India', 'state' => 'Karnataka', 'city' => 'Bengaluru', 'state_code' => 'KA', 'city_code' => 'BLR', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],

            ['country' => 'India', 'state' => 'Kerala', 'city' => 'Kochi', 'state_code' => 'KL', 'city_code' => 'KO', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ];

        DB::table('cities')->upsert(
            $cities,
            ['state', 'city'], // Unique keys to check against duplicate records
            ['state_code', 'city_code', 'is_active', 'updated_at'] // Fields to update if match found
        );
    }
}
