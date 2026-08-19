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
        Schema::table('mis_batches', function (Blueprint $table) {
            //
            // $table->unsignedInteger('successful_rows')->default(0);
            $table->unsignedInteger('lan_not_found_rows')->default(0);
            $table->unsignedInteger('validation_failed_rows')->default(0);
            $table->unsignedInteger('processing_failed_rows')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mis_batches', function (Blueprint $table) {
            //
        });
    }
};
