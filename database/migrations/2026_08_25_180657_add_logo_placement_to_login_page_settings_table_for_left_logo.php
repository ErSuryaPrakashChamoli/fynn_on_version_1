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
            // Same placement controls as the company logo, but for the
            // FYNN-ON "Logo" field -- lets it move between the two
            // panels too. Defaults match where it always used to be
            // (over the left banner, top, centered).
            $table->string('left_logo_side')->default('left')->after('left_logo_path');
            $table->string('left_logo_vertical_align')->default('top')->after('left_logo_side');
            $table->string('left_logo_horizontal_align')->default('center')->after('left_logo_vertical_align');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('login_page_settings', function (Blueprint $table) {
            $table->dropColumn([
                'left_logo_side',
                'left_logo_vertical_align',
                'left_logo_horizontal_align',
            ]);
        });
    }
};
