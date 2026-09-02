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
        Schema::table('login_page_settings', function (Blueprint $table) {
            // A full custom banner image for the entire left panel. When
            // set, it's rendered as-is (the admin's own design) instead of
            // the built-in logo/heading/tagline composition -- see
            // resources/views/filament/auth/login-layout.blade.php.
            $table->string('left_banner_path')->nullable()->after('left_logo_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('login_page_settings', function (Blueprint $table) {
            $table->dropColumn('left_banner_path');
        });
    }
};
