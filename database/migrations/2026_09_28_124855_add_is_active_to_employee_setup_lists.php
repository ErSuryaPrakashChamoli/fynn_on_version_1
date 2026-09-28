<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An inactive option is no longer offered on the Employee / User forms, but
 * everyone who already holds it keeps it.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const TABLES = ['designations', 'positions', 'target_categories', 'cost_centers', 'units', 'roles'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->boolean('is_active')->default(true);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('is_active');
            });
        }
    }
};
