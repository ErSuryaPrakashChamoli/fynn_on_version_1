<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Target category amounts are dated by month (user decision 2026-11-24):
 * a new amount applies from the month the Admin picks, and closed months
 * keep the amount they had. Today's amounts are recorded as having applied
 * from the start of time, so no month's figure moves when this ships.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('target_category_amounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_category_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            $table->unsignedBigInteger('amount')->nullable();
            $table->timestamps();

            $table->unique(['target_category_id', 'effective_from']);
        });

        $now = now();

        foreach (DB::table('target_categories')->get(['id', 'target_amount']) as $category) {
            DB::table('target_category_amounts')->insert([
                'target_category_id' => $category->id,
                'effective_from' => '2000-01-01',
                'amount' => $category->target_amount,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('target_category_amounts');
    }
};
