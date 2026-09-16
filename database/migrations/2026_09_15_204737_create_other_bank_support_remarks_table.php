<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step-wise remarks the Other Bank Support team leaves on a customer file
 * that is eligible for a bank other than the in-house BFL products. Read by
 * the file's owner and everyone above them; never changes the journey.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('other_bank_support_remarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stage', 30);
            $table->text('remark');
            $table->timestamps();

            $table->index(['customer_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('other_bank_support_remarks');
    }
};
