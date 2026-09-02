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
            ALTER TABLE customers
            MODIFY eligibility_status
            ENUM('eligible', 'not_eligible', 'consent_pending')
            NOT NULL DEFAULT 'eligible'
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
            ALTER TABLE customers
            MODIFY eligibility_status
            ENUM('eligible', 'not_eligible')
            NOT NULL DEFAULT 'eligible'
        ");
    }
};
