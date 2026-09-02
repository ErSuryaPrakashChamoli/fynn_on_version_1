<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocr_documents', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->after('is_verified')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->json('approved_data')->nullable()->after('approved_at');
            $table->text('rejection_reason')->nullable()->after('approved_data');
        });
    }

    public function down(): void
    {
        Schema::table('ocr_documents', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropColumn(['approved_by', 'approved_at', 'approved_data', 'rejection_reason']);
        });
    }
};
