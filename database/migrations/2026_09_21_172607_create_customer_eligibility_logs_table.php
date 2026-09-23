<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The eligibility log shown on a customer file to its owner, everyone
 * above them and the Admin: every status change, request, approval and
 * rejection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_eligibility_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // Explicit short name: the default FK name is over MySQL's 64-character limit.
            $table->foreignId('customer_eligibility_request_id')->nullable()->constrained(indexName: 'cel_request_id_foreign')->nullOnDelete();
            $table->string('event', 30);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_eligibility_logs');
    }
};
