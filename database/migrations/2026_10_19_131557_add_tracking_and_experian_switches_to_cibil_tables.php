<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Additive only.
 *
 * cibil_reports gains what the monitoring screens need for every request:
 * a correlation id (uuid), how long the provider took (duration_ms), why it
 * failed (failure_category), and for a saved PDF its size, SHA-256 checksum
 * and when it was fetched. Existing rows get a uuid; the rest stays null.
 *
 * cibil_settings gains Experian's own switches — the bureau as a whole and
 * each request type — so Experian is controlled independently of CIBIL. They
 * start ON (user decision 2026-10-17: both bureaus are offered in the LMS);
 * nothing goes to any bureau while the existing master switch is off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cibil_reports', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
            $table->unsignedInteger('duration_ms')->nullable()->after('completed_at');
            $table->string('failure_category', 40)->nullable()->index()->after('error_message');
            $table->unsignedInteger('pdf_size_bytes')->nullable()->after('pdf_path');
            $table->char('pdf_checksum', 64)->nullable()->after('pdf_size_bytes');
            $table->timestamp('pdf_fetched_at')->nullable()->after('pdf_checksum');
        });

        DB::table('cibil_reports')->whereNull('uuid')->orderBy('id')->select('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('cibil_reports')->where('id', $row->id)->update(['uuid' => (string) Str::uuid()]);
            }
        });

        Schema::table('cibil_settings', function (Blueprint $table) {
            $table->boolean('experian_enabled')->default(true)->after('pdf_enabled');
            $table->boolean('experian_score_enabled')->default(true)->after('experian_enabled');
            $table->boolean('experian_full_report_enabled')->default(true)->after('experian_score_enabled');
            $table->boolean('experian_pdf_enabled')->default(true)->after('experian_full_report_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('cibil_settings', function (Blueprint $table) {
            $table->dropColumn(['experian_enabled', 'experian_score_enabled', 'experian_full_report_enabled', 'experian_pdf_enabled']);
        });

        Schema::table('cibil_reports', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropIndex(['failure_category']);
            $table->dropColumn(['uuid', 'duration_ms', 'failure_category', 'pdf_size_bytes', 'pdf_checksum', 'pdf_fetched_at']);
        });
    }
};
