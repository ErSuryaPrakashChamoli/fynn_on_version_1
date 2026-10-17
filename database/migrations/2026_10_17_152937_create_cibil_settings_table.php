<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Admin's switches for the CIBIL module (App\Models\CibilSetting).
 * Requests start switched OFF on every environment: nothing is sent to the
 * (billable) bureau until the Admin turns it on in CIBIL Settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cibil_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('requests_enabled')->default(false);
            $table->boolean('score_enabled')->default(true);
            $table->boolean('full_report_enabled')->default(true);
            $table->boolean('pdf_enabled')->default(true);
            $table->boolean('fresh_requests_enabled')->default(true);
            // Null: a fetched report is reused until someone asks for a fresh one.
            $table->unsignedSmallInteger('reuse_window_days')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // A singleton settings row, the same pattern as DashboardGreetingSetting.
        DB::table('cibil_settings')->insert([
            'requests_enabled' => false,
            'score_enabled' => true,
            'full_report_enabled' => true,
            'pdf_enabled' => true,
            'fresh_requests_enabled' => true,
            'reuse_window_days' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('cibil_settings');
    }
};
