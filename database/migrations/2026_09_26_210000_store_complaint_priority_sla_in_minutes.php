<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The priority SLA moved from hours to minutes on 2026-09-26 so that a
 * Critical ticket can be due within 10 minutes. The create migration now
 * makes the column in minutes directly (fresh installs and the test suite
 * never see this); this one converts a database that already ran it in
 * hours, and moves the four seeded priorities that still hold the old
 * defaults (72h / 48h / 24h / 4h) to the new ones (2 days / a day / an
 * hour / 10 minutes). A priority the Admin has already edited keeps its
 * value, converted.
 */
return new class extends Migration
{
    /**
     * @var array<string, array{0: int, 1: int}> name => [old default in minutes, new default in minutes]
     */
    private const array DEFAULTS = [
        'Low' => [72 * 60, 2 * 1440],
        'Medium' => [48 * 60, 1440],
        'High' => [24 * 60, 60],
        'Critical' => [4 * 60, 10],
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('complaint_priorities', 'resolve_within_hours')) {
            return;
        }

        Schema::table('complaint_priorities', function (Blueprint $table): void {
            $table->renameColumn('resolve_within_hours', 'resolve_within_minutes');
        });

        DB::table('complaint_priorities')->update([
            'resolve_within_minutes' => DB::raw('resolve_within_minutes * 60'),
        ]);

        foreach (self::DEFAULTS as $name => [$oldDefault, $newDefault]) {
            DB::table('complaint_priorities')
                ->where('name', $name)
                ->where('resolve_within_minutes', $oldDefault)
                ->update(['resolve_within_minutes' => $newDefault]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('complaint_priorities', 'resolve_within_minutes')) {
            return;
        }

        foreach (self::DEFAULTS as $name => [$oldDefault, $newDefault]) {
            DB::table('complaint_priorities')
                ->where('name', $name)
                ->where('resolve_within_minutes', $newDefault)
                ->update(['resolve_within_minutes' => $oldDefault]);
        }

        DB::table('complaint_priorities')->update([
            'resolve_within_minutes' => DB::raw('CEILING(resolve_within_minutes / 60.0)'),
        ]);

        Schema::table('complaint_priorities', function (Blueprint $table): void {
            $table->renameColumn('resolve_within_minutes', 'resolve_within_hours');
        });
    }
};
