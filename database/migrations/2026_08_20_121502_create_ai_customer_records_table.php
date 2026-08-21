<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_customer_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schema_id')->constrained('ai_document_schemas')->cascadeOnDelete();
            $table->foreignId('ocr_document_id')->nullable()->constrained('ocr_documents')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->json('data');
            $table->enum('status', ['pending', 'review', 'approved', 'rejected'])->default('review')->index();
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['schema_id', 'status']);
            $table->index(['customer_id', 'schema_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_customer_records');
    }
};
