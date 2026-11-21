<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The cashback analysis (cases punched in, cases with cashback, cashback,
 * disbursal and approval amounts, cashback %) of everyone on the case's
 * chain, saved when the form is raised so the approver's basis stays on
 * record (CashbackAnalysisService::forForm()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_cashback_requests', function (Blueprint $table): void {
            $table->json('analysis_snapshot')->nullable()->after('payment_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('customer_cashback_requests', function (Blueprint $table): void {
            $table->dropColumn('analysis_snapshot');
        });
    }
};
