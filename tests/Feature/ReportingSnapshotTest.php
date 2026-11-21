<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Support\ReportingSnapshot;
use App\Support\ReportingTimeline;
use App\Support\ReportingTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every hierarchy walk used to re-read employees and
 * employee_reporting_history and rebuild the tree: 52 times on one
 * dashboard load. ReportingSnapshot shares one read per request, and any
 * write to either table, however it was issued, retires it so a walk never
 * sees a stale tree.
 */
class ReportingSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private Employee $teamLeader;

    private Employee $otherTeamLeader;

    private Employee $caller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teamLeader = Employee::factory()->create(['designation' => Employee::DESIGNATION_TEAM_LEADER, 'exit_status' => 'no']);
        $this->otherTeamLeader = Employee::factory()->create(['designation' => Employee::DESIGNATION_TEAM_LEADER, 'exit_status' => 'no']);
        $this->caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'exit_status' => 'no',
            'superviser_id' => $this->teamLeader->id,
        ]);
    }

    public function test_the_reporting_tables_are_read_once_however_many_walks_a_request_makes(): void
    {
        DB::enableQueryLog();

        for ($walk = 0; $walk < 10; $walk++) {
            ReportingTree::load()->descendantIds($this->teamLeader->id);
            ReportingTimeline::forMonth(now())->branchSpans($this->teamLeader->id);
            ReportingTimeline::forMonth(now())->headcountCallerIds($this->teamLeader->id);
        }

        $reads = collect(DB::getQueryLog())->pluck('query')
            ->filter(fn (string $sql): bool => preg_match('/\bfrom\s+["`]?(employees|employee_reporting_history)\b/i', $sql) === 1);

        // employees for the tree, employees for the on-rolls window, and the
        // two history reads (reporting lines, designations) — once each.
        $this->assertCount(4, $reads);
        $this->assertSame(
            [$this->caller->id],
            ReportingTree::load()->descendantIds($this->teamLeader->id),
        );
    }

    public function test_an_eloquent_save_is_seen_by_the_next_walk(): void
    {
        $this->assertSame($this->teamLeader->id, ReportingTree::load()->bossId($this->caller->id));

        $this->caller->update(['superviser_id' => $this->otherTeamLeader->id]);

        $this->assertSame($this->otherTeamLeader->id, ReportingTree::load()->bossId($this->caller->id));
        $this->assertSame(
            $this->otherTeamLeader->id,
            ReportingTimeline::forMonth(now())->treeOn(now())->bossId($this->caller->id),
        );
    }

    public function test_a_query_builder_write_is_seen_by_the_next_walk(): void
    {
        $this->assertSame([$this->caller->id], ReportingTree::load()->childIds($this->teamLeader->id));

        DB::table('employees')->where('id', $this->caller->id)->update(['superviser_id' => $this->otherTeamLeader->id]);

        $this->assertSame([], ReportingTree::load()->childIds($this->teamLeader->id));
        $this->assertSame([$this->caller->id], ReportingTree::load()->childIds($this->otherTeamLeader->id));
    }

    public function test_a_new_employee_is_seen_by_the_next_walk(): void
    {
        ReportingTree::load();

        $newCaller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'exit_status' => 'no',
            'superviser_id' => $this->teamLeader->id,
        ]);

        $this->assertEqualsCanonicalizing(
            [$this->caller->id, $newCaller->id],
            ReportingTree::load()->childIds($this->teamLeader->id),
        );
    }

    public function test_a_reporting_history_write_retires_the_memo(): void
    {
        $this->assertSame('first', ReportingSnapshot::remember('probe', fn (): string => 'first'));
        $this->assertSame('first', ReportingSnapshot::remember('probe', fn (): string => 'second'));

        DB::table('employee_reporting_history')->insert([
            'employee_id' => $this->caller->id,
            'old_superviser_id' => $this->teamLeader->id,
            'new_superviser_id' => $this->otherTeamLeader->id,
            'effective_date' => now()->toDateString(),
            'change_type' => 'transfer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('third', ReportingSnapshot::remember('probe', fn (): string => 'third'));
    }

    public function test_a_rolled_back_change_is_not_kept(): void
    {
        DB::beginTransaction();
        $this->caller->update(['superviser_id' => $this->otherTeamLeader->id]);
        $this->assertSame($this->otherTeamLeader->id, ReportingTree::load()->bossId($this->caller->id));
        DB::rollBack();

        $this->assertSame($this->teamLeader->id, ReportingTree::load()->bossId($this->caller->id));
    }

    public function test_unrelated_writes_keep_the_memo(): void
    {
        ReportingSnapshot::remember('probe', fn (): string => 'kept');

        DB::table('employee_inactivity_requests')->delete();
        Employee::query()->count();

        $this->assertSame('kept', ReportingSnapshot::remember('probe', fn (): string => 'rebuilt'));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function statements(): array
    {
        return [
            'read of employees' => ['select * from `employees` where `id` = ?', false],
            'mysql insert into employees' => ['insert into `employees` (`emp_name`) values (?)', true],
            'sqlite update of employees' => ['update "employees" set "superviser_id" = ? where "id" = ?', true],
            'delete from history' => ['delete from `employee_reporting_history` where `id` = ?', true],
            'unquoted raw update' => ['  UPDATE employees SET exit_status = "yes"', true],
            'truncate of history' => ['truncate table employee_reporting_history', true],
            'other employee table' => ['insert into `employee_inactivity_requests` (`employee_id`) values (?)', false],
            'employee_id column elsewhere' => ['update `users` set `employee_id` = ? where `id` = ?', false],
            'read of history' => ['select * from `employee_reporting_history` order by `id`', false],
        ];
    }

    #[DataProvider('statements')]
    public function test_it_recognises_statements_that_change_the_reporting_data(string $sql, bool $changes): void
    {
        $this->assertSame($changes, ReportingSnapshot::changesReportingData($sql));
    }
}
