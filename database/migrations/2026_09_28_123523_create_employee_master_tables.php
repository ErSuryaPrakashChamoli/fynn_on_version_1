<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Admin-managed lists behind the Employee form's dropdowns, which used to be
 * hardcoded. Each table is seeded with the values the form offered before,
 * plus any other value already sitting on an employee, so no existing
 * employee is left pointing at an option that does not exist.
 *
 * Note the UI labels are swapped against the column names:
 *  - "Designation" (designations table)  -> employees.position  (stores the name)
 *  - "Position"    (positions table)     -> employees.designation (stores the id)
 */
return new class extends Migration
{
    /**
     * The hierarchy codes the application logic is written against — see
     * the DESIGNATION_* constants on Employee. Their ids must never change.
     *
     * @var array<int, string>
     */
    private const SYSTEM_POSITIONS = [
        1 => 'Admin',
        2 => 'Manager',
        3 => 'Team Leader',
        5 => 'Cluster Manager',
        7 => 'Caller',
        9 => 'Business Head',
        11 => 'Other Bank Support',
    ];

    /**
     * @var array<int, array{code: string, name: string, target_amount: int|null}>
     */
    private const TARGET_CATEGORIES = [
        ['code' => '2500000', 'name' => 'Silver', 'target_amount' => 2500000],
        ['code' => '3000000', 'name' => 'Gold', 'target_amount' => 3000000],
        ['code' => '3500000', 'name' => 'Diamond', 'target_amount' => 3500000],
        ['code' => 'team_leader', 'name' => 'Alpha', 'target_amount' => null],
        ['code' => 'manager', 'name' => 'Beta', 'target_amount' => null],
        ['code' => 'cluster_manager', 'name' => 'Delta', 'target_amount' => null],
    ];

    /**
     * @var array<string, string>
     */
    private const COST_CENTERS = [
        'anuj_singh_thakur' => 'Anuj Singh Thakur',
        'bhupendra_singh' => 'Bhupendra Singh',
        'chanchal_chaudhary' => 'Chanchal Chaudhary',
        'deepak_singh' => 'Deepak Singh',
        'kanak_kumar' => 'Kanak Kumar',
        'manoj_sajwan' => 'Manoj Sajwan',
        'nitin_thakur' => 'Nitin Thakur',
        'prabhat_tyagi' => 'Prabhat Tyagi',
        'rohit_sharma' => 'Rohit Sharma',
    ];

    /**
     * @var array<string, string>
     */
    private const UNITS = [
        'kanak_kumar' => 'Kanak Kumar',
        'rohit_sharma' => 'Rohit Sharma',
    ];

    public function up(): void
    {
        Schema::create('designations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('target_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code')->unique();
            $table->unsignedBigInteger('target_amount')->nullable();
            $table->timestamps();
        });

        Schema::create('cost_centers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code')->unique();
            $table->timestamps();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code')->unique();
            $table->timestamps();
        });

        $now = now();

        foreach (self::SYSTEM_POSITIONS as $id => $name) {
            DB::table('positions')->insert(['id' => $id, 'name' => $name, 'is_system' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        // Keep new, admin-added positions clear of the reserved codes on
        // databases whose sequence does not follow explicit inserts.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT setval('positions_id_seq', (SELECT MAX(id) FROM positions))");
        }

        foreach (self::TARGET_CATEGORIES as $category) {
            DB::table('target_categories')->insert($category + ['created_at' => $now, 'updated_at' => $now]);
        }

        $this->seedCodedList('cost_centers', 'cost_center', self::COST_CENTERS);
        $this->seedCodedList('units', 'unit_name', self::UNITS);

        // Designations were free text, so the list starts as whatever is on
        // file today (trimmed; stray trailing spaces split one title in two).
        DB::table('employees')->whereNotNull('position')->update(['position' => DB::raw('TRIM(position)')]);

        DB::table('employees')
            ->whereNotNull('position')
            ->where('position', '!=', '')
            ->distinct()
            ->orderBy('position')
            ->pluck('position')
            ->unique(fn (string $name): string => mb_strtolower($name))
            ->each(fn (string $name) => DB::table('designations')->insert(['name' => $name, 'created_at' => $now, 'updated_at' => $now]));
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
        Schema::dropIfExists('cost_centers');
        Schema::dropIfExists('target_categories');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('designations');
    }

    /**
     * @param  array<string, string>  $defaults  code => name
     */
    private function seedCodedList(string $table, string $employeeColumn, array $defaults): void
    {
        $inUse = DB::table('employees')
            ->whereNotNull($employeeColumn)
            ->where($employeeColumn, '!=', '')
            ->distinct()
            ->pluck($employeeColumn)
            ->mapWithKeys(fn (string $code): array => [$code => Str::headline($code)])
            ->all();

        $names = [];

        foreach ($defaults + $inUse as $code => $name) {
            // Two codes that read the same (e.g. "Kanak Kumar" and
            // "kanak_kumar") still need distinct names.
            $uniqueName = isset($names[mb_strtolower($name)]) ? "{$name} ({$code})" : $name;
            $names[mb_strtolower($uniqueName)] = true;

            DB::table($table)->insert([
                'code' => $code,
                'name' => $uniqueName,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
