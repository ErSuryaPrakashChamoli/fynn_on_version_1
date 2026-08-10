<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customer_pan_requests', function (Blueprint $table) {
            //
            $table->string('pan_number', 10)
                ->nullable()
                ->after('customer_id')
                ->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_pan_requests', function (Blueprint $table) {
            //
            $table->dropIndex(['pan_number']);
            $table->dropColumn('pan_number');

        });
    }
};
