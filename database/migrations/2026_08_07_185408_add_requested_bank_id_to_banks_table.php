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
        Schema::table('banks', function (Blueprint $table) {
            //
             $table->foreignId('requested_bank_id')
                ->nullable()
                ->after('loan_type')
                ->constrained('banks')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('banks', function (Blueprint $table) {
            //
            $table->dropForeign(['requested_bank_id']);
            $table->dropColumn('requested_bank_id');
        });
    }
};
