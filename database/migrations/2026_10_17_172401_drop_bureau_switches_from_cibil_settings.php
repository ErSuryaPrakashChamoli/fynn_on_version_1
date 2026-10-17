<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User decision 2026-10-17: CIBIL and Experian are always both offered — no
 * per-bureau switch. An earlier version of the bureau migration added
 * cibil_enabled / experian_enabled to cibil_settings on the environments
 * that ran it; this removes them there and does nothing elsewhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = array_values(array_filter(
            ['cibil_enabled', 'experian_enabled'],
            fn (string $column): bool => Schema::hasColumn('cibil_settings', $column),
        ));

        if ($columns === []) {
            return;
        }

        Schema::table('cibil_settings', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }

    /** The switches are gone for good; there is nothing to restore. */
    public function down(): void {}
};
