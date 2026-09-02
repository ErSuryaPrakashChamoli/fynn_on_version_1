<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('dashboard_greeting_settings', function (Blueprint $table) {
            $table->id();
            $table->string('tagline');
            $table->timestamps();
        });

        // A singleton settings row -- App\Models\DashboardGreetingSetting
        // always reads/updates this one record, the same pattern as
        // LoginPageSetting.
        DB::table('dashboard_greeting_settings')->insert([
            'tagline' => "Let's make every move count!",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dashboard_greeting_settings');
    }
};
