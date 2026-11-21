<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A case punched in before cashback forms existed may already carry a typed
 * cashback. When its first form is filled, that figure is kept here, and
 * customers.cashback becomes this amount plus every live form's amount —
 * a new cashback adds to the old one instead of replacing it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->decimal('cashback_before_forms', 12, 2)->nullable()->after('cashback_option');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('cashback_before_forms');
        });
    }
};
