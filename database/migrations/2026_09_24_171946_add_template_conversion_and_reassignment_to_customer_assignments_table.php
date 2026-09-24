<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot the template (AI document schema) a lead came from, when it was
     * converted to a customer, and how often it has been handed to someone else.
     *
     * The template is stored on the assignment because converting a lead nulls
     * customer_assignments.ai_customer_record_id, which would otherwise lose it.
     */
    public function up(): void
    {
        Schema::table('customer_assignments', function (Blueprint $table) {
            $table->foreignId('ai_document_schema_id')
                ->nullable()
                ->after('ai_customer_record_id')
                ->constrained('ai_document_schemas')
                ->nullOnDelete();
            $table->timestamp('converted_at')->nullable()->after('last_opened_at');
            $table->unsignedInteger('reassign_count')->default(0)->after('converted_at');
            $table->timestamp('last_reassigned_at')->nullable()->after('reassign_count');
        });

        DB::table('customer_assignments')
            ->select(['id', 'customer_id', 'ai_customer_record_id'])
            ->orderBy('id')
            ->chunkById(500, function ($assignments): void {
                foreach ($assignments as $assignment) {
                    $record = DB::table('ai_customer_records')
                        ->when(
                            $assignment->ai_customer_record_id,
                            fn ($query) => $query->where('id', $assignment->ai_customer_record_id),
                            fn ($query) => $query->where('customer_id', $assignment->customer_id)
                        )
                        ->first(['schema_id', 'customer_id']);

                    if (! $record) {
                        continue;
                    }

                    $isConverted = blank($assignment->ai_customer_record_id) && filled($assignment->customer_id);

                    DB::table('customer_assignments')->where('id', $assignment->id)->update([
                        'ai_document_schema_id' => $record->schema_id,
                        'converted_at' => $isConverted
                            ? DB::table('customers')->where('id', $assignment->customer_id)->value('created_at')
                            : null,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('customer_assignments', function (Blueprint $table) {
            $table->dropForeign(['ai_document_schema_id']);
            $table->dropColumn(['ai_document_schema_id', 'converted_at', 'reassign_count', 'last_reassigned_at']);
        });
    }
};
