<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The owner's authenticator app that approves every Leads / Customers
     * data download. A single row; the secret is stored encrypted.
     */
    public function up(): void
    {
        Schema::create('data_download_authenticators', function (Blueprint $table) {
            $table->id();
            $table->string('owner_name');
            $table->text('secret');
            $table->unsignedBigInteger('last_used_timestep')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->foreignId('configured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('configured_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_download_authenticators');
    }
};
