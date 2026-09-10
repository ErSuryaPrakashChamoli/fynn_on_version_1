<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_loan_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('min_amount')->default(0);
            $table->unsignedBigInteger('max_amount')->default(0);
            $table->unsignedSmallInteger('min_tenure_months')->default(12);
            $table->unsignedSmallInteger('max_tenure_months')->default(60);
            $table->decimal('interest_rate_from', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_loan_products');
    }
};
