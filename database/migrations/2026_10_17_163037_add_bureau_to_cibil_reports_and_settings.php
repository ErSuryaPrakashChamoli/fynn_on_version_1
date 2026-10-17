<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A request now goes to the bureau the user picks: CIBIL or Experian. Every
 * existing row was a CIBIL request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cibil_reports', function (Blueprint $table) {
            $table->string('bureau', 20)->default('cibil')->after('request_type');
            $table->index(['pan_number', 'bureau', 'request_type']);
        });
    }

    public function down(): void
    {
        Schema::table('cibil_reports', function (Blueprint $table) {
            $table->dropIndex(['pan_number', 'bureau', 'request_type']);
            $table->dropColumn('bureau');
        });
    }
};
