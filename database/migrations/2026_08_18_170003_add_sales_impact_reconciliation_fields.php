<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_settlements', function (Blueprint $table) {
            $table->decimal('achievement_before', 15, 2)->nullable()->after('sales_incentive');
            $table->decimal('achievement_after', 15, 2)->nullable()->after('achievement_before');
            $table->decimal('achievement_difference', 15, 2)->nullable()->after('achievement_after');
            $table->decimal('incentive_before', 15, 2)->nullable()->after('achievement_difference');
            $table->decimal('incentive_after', 15, 2)->nullable()->after('incentive_before');
            $table->decimal('incentive_difference', 15, 2)->nullable()->after('incentive_after');
            $table->timestamp('impact_calculated_at')->nullable()->after('incentive_difference');
        });
    }

    public function down(): void
    {
        Schema::table('customer_settlements', function (Blueprint $table) {
            $table->dropColumn([
                'achievement_before',
                'achievement_after',
                'achievement_difference',
                'incentive_before',
                'incentive_after',
                'incentive_difference',
                'impact_calculated_at',
            ]);
        });
    }
};
