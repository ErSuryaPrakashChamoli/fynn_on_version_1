<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE customer_settlements MODIFY status ENUM(
            'pending','mis_review','mis_verified','variance','accounts_review','payment_pending',
            'partially_paid','paid','recovery_pending','verified','rejected','settled','hold'
        ) DEFAULT 'pending'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE customer_settlements MODIFY status ENUM(
            'pending','variance','verified','rejected','settled'
        ) DEFAULT 'pending'");
    }
};
