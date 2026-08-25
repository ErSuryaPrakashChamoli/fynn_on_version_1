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
            MODIFY COLUMN disbursal_status
            ENUM('disbursed', 'dropped', 'carry_forward', 'on_hold')
            NULL
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
            MODIFY COLUMN disbursal_status
            ENUM('disbursed', 'dropped', 'carry_forward')
            NULL
           ");
    }
};
