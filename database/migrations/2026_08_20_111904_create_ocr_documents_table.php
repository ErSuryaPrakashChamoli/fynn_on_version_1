<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocr_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title')->nullable();
            $table->string('document_type')->nullable();
            $table->string('original_path');
            $table->string('original_name');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('page_count')->nullable();
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending')->index();
            $table->longText('ocr_text')->nullable();
            $table->json('extracted_data')->nullable();
            $table->json('page_data')->nullable();
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->text('error_message')->nullable();
            $table->boolean('is_verified')->default(false)->index();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index('document_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_documents');
    }
};
