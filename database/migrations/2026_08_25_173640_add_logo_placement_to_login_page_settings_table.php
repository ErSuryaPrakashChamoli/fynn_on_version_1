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
            // Where the company (Fynnedge Advisory) logo renders: which of
            // the two panels ("left" = the dark banner, "right" = the
            // white sign-in panel), and its vertical/horizontal position
            // within that panel. Defaults match where it always used to be
            // (top of the right panel, centered).
            $table->string('right_logo_side')->default('right')->after('right_logo_path');
            $table->string('right_logo_vertical_align')->default('top')->after('right_logo_side');
            $table->string('right_logo_horizontal_align')->default('center')->after('right_logo_vertical_align');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('login_page_settings', function (Blueprint $table) {
            $table->dropColumn([
                'right_logo_side',
                'right_logo_vertical_align',
                'right_logo_horizontal_align',
            ]);
        });
    }
};
