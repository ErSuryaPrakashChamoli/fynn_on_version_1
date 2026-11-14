<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The first day a Unit / Cost Center stopped being active, so a report for
 * an earlier date still treats it as active.
 *
 * Rows already switched off before this column existed are given the day
 * they were last saved — the closest record of when that happened.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const TABLES = ['units', 'cost_centers'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->date('inactive_from')->nullable()->after('is_active');
            });

            DB::table($table)
                ->where('is_active', false)
                ->get(['id', 'updated_at', 'created_at'])
                ->each(fn (object $row) => DB::table($table)->where('id', $row->id)->update([
                    'inactive_from' => Carbon::parse($row->updated_at ?? $row->created_at ?? now())->toDateString(),
                ]));
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('inactive_from');
            });
        }
    }
};
