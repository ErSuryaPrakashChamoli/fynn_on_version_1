<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A PAN request snapshots the requester's reporting line when it is
 * raised. The Business Head level joins Team Leader, Manager and Cluster
 * Manager in that snapshot. Existing requests keep a null Business Head;
 * the listing still reaches them through the requester's branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_pan_requests', function (Blueprint $table) {
            $table->foreignId('business_head_id')
                ->nullable()
                ->after('cluster_manager_name')
                ->constrained('employees')
                ->nullOnDelete();

            $table->string('business_head_name')
                ->nullable()
                ->after('business_head_id');
        });
    }

    public function down(): void
    {
        Schema::table('customer_pan_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_head_id');
            $table->dropColumn('business_head_name');
        });
    }
};
