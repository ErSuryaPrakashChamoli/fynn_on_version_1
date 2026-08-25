<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customer_assignments', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE customer_assignments MODIFY customer_id BIGINT UNSIGNED NULL');
        }

        Schema::table('customer_assignments', function (Blueprint $table) {
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();

            $table->foreignId('ai_customer_record_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('ai_customer_records')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_assignments', function (Blueprint $table) {
            $table->dropForeign(['ai_customer_record_id']);
            $table->dropColumn('ai_customer_record_id');

            $table->dropForeign(['customer_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE customer_assignments MODIFY customer_id BIGINT UNSIGNED NOT NULL');
        }

        Schema::table('customer_assignments', function (Blueprint $table) {
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
        });
    }
};
