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
        Schema::table('dashboard_greeting_settings', function (Blueprint $table) {
            // Either a "heroicon-o-{slug}" string (rendered via
            // <x-filament::icon>) or a plain emoji character (rendered as
            // text) -- see DashboardGreetingSettings::iconOptions() for the
            // selectable set. Defaults to the rocket so existing rows keep
            // their current look after this migration runs.
            $table->string('icon')->default('heroicon-o-rocket-launch');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dashboard_greeting_settings', function (Blueprint $table) {
            $table->dropColumn('icon');
        });
    }
};
