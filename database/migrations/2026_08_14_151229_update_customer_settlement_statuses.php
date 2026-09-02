<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("
            ALTER TABLE customer_settlements
            MODIFY status ENUM(
                'pending',
                'variance',
                'verified',
                'rejected',
                'settled'
            ) DEFAULT 'pending'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("
            ALTER TABLE customer_settlements
            MODIFY status ENUM(
                'pending',
                'verified',
                'rejected'
            ) DEFAULT 'pending'
        ");
    }
};
