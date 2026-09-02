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
        Schema::create('login_page_settings', function (Blueprint $table) {
            $table->id();
            $table->string('left_logo_path')->nullable();
            $table->string('left_heading')->nullable();
            $table->string('left_tagline')->nullable();
            $table->string('right_logo_path')->nullable();
            $table->string('right_tagline')->nullable();
            $table->string('welcome_heading')->nullable();
            $table->string('welcome_subheading')->nullable();
            $table->string('footer_text')->nullable();
            $table->timestamps();
        });

        // A singleton settings row -- the model always reads/updates this
        // one record. Logo paths are left null so the model's URL
        // accessors fall back to the existing static brand assets in
        // public/images/ until an admin uploads a replacement.
        DB::table('login_page_settings')->insert([
            'left_heading' => 'One Platform. One Team. One Goal.',
            'left_tagline' => 'LEAD • MANAGE • SUCCEED',
            'right_tagline' => 'Simplifying Loan, Amplifying Trust.',
            'welcome_heading' => 'Welcome Back!',
            'welcome_subheading' => 'Sign in to continue to Fynn-ON LMS',
            'footer_text' => '© '.now()->year.' Markedge Technologies. All rights reserved.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('login_page_settings');
    }
};
