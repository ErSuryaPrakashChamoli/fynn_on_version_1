<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_customer_records', function (Blueprint $table) {
            //
            $table->boolean('is_duplicate')->default(false)->index();
            $table->foreignId('duplicate_of_id')
                ->nullable()
                ->constrained('ai_customer_records')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_customer_records', function (Blueprint $table) {
            //
            $table->dropForeign(['duplicate_of_id']);
            $table->dropColumn(['is_duplicate', 'duplicate_of_id']);
        });
    }
};
