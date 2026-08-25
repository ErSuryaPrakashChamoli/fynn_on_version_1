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
            // Admin-configurable typography for the two large headings
            // (left banner + right "welcome" heading), so long text (e.g.
            // "Welcome Back! Work Mode ON!") can be sized/aligned to avoid
            // an awkward wrap instead of being stuck with a fixed style.
            $table->string('left_heading_size')->default('lg')->after('left_heading');
            $table->string('left_heading_align')->default('center')->after('left_heading_size');
            $table->string('welcome_heading_size')->default('lg')->after('welcome_heading');
            $table->string('welcome_heading_align')->default('center')->after('welcome_heading_size');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('login_page_settings', function (Blueprint $table) {
            $table->dropColumn([
                'left_heading_size',
                'left_heading_align',
                'welcome_heading_size',
                'welcome_heading_align',
            ]);
        });
    }
};
