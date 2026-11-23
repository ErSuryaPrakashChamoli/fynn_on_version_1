<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cashback form also declares the case's subvention. The case's
 * subvention follows the latest live form that declared one, at once (a
 * pending form counts, like its cashback); customers.subvention_before_forms
 * keeps what the case carried before the first such form, so rejecting or
 * retrieving every declaring form puts it back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_cashback_requests', function (Blueprint $table): void {
            $table->decimal('subvention_amount', 12, 2)->nullable()->after('approved_amount');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->decimal('subvention_before_forms', 12, 2)->nullable()->after('cashback_before_forms');
        });
    }

    public function down(): void
    {
        Schema::table('customer_cashback_requests', function (Blueprint $table): void {
            $table->dropColumn('subvention_amount');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('subvention_before_forms');
        });
    }
};
