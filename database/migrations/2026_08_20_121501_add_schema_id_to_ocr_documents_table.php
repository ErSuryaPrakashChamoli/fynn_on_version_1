<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocr_documents', function (Blueprint $table) {
            $table->foreignId('schema_id')->nullable()->after('document_type')->constrained('ai_document_schemas')->nullOnDelete();
            $table->index(['schema_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('ocr_documents', function (Blueprint $table) {
            $table->dropForeign(['schema_id']);
            $table->dropIndex(['schema_id', 'status']);
            $table->dropColumn('schema_id');
        });
    }
};
