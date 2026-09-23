<?php

namespace Database\Seeders\DemoEnvironment;

use App\Enums\CommitmentResult;
use App\Enums\CommitmentStage;
use App\Models\Employee;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Monthly targets and the daily commitment module for every Manager,
 * Team Leader and Caller, plus the Other Bank Support desk's targets,
 * slabs and remarks.
 *
 * The current month's targets are all in place and every past day is
 * declared, so neither the monthly-target gate nor the daily commitment
 * prompt stops a persona; today's commitment is made but still open,
 * which is what the dashboards look like mid-day.
 */
class CommitmentSeeder extends Seeder
{
    private const HISTORY_MONTHS = 2;

    private DemoWorld $world;

    /** @var array<string, true> */
    private array $usedEntryMobiles = [];

    public function run(DemoWorld $world): void
    {
        $this->world = $world;

        $this->seedMonthlyTargets();
        $this->seedDailyCommitments();
        $this->seedExpectedOtps();
        $this->seedInactivityRequests();
        $this->seedOtherBankSupport();
    }

    private function seedMonthlyTargets(): void
    {
        $rows = [];

        foreach (range(self::HISTORY_MONTHS, 0) as $offset) {
            $month = $this->world->monthStart($offset);

            foreach ($this->committers($month) as $employee) {
                $rows[] = [
                    'employee_id' => $employee->id,
                    'month' => $month->toDateString(),
                    'stage' => CommitmentStage::Disbursed->value,
                    'target_amount' => $this->monthlyTargetFor($employee, $month),
                    'target_count' => 0,
                    'created_at' => $month->copy()->setTime(10, 5),
                    'updated_at' => $month->copy()->setTime(10, 5),
                ];
            }
        }

        $this->world->insert('monthly_commitment_targets', $rows);
    }

    private function monthlyTargetFor(object $employee, Carbon $month): int
    {
        $callerTarget = function (object $caller) use ($month): int {
            $joined = Carbon::parse($caller->reporting_date ?? $caller->doj);

            return $joined->greaterThan($month) ? 1500000 : (int) ($caller->category ?: 2500000);
        };

        return match ((int) $employee->designation) {
            Employee::DESIGNATION_CALLER => $callerTarget($employee),
            Employee::DESIGNATION_TEAM_LEADER => $this->activeIn($month)->where('superviser_id', $employee->id)->where('designation', Employee::DESIGNATION_CALLER)->sum($callerTarget),
            default => $this->activeIn($month)->where('manager_id', $employee->id)->where('designation', Employee::DESIGNATION_CALLER)->sum($callerTarget),
        };
    }

    private function seedDailyCommitments(): void
    {
        $from = $this->world->monthStart(self::HISTORY_MONTHS);
        $commitments = [];
        $entryPlans = [];

        foreach ($this->world->workingDays($from, $this->world->today) as $day) {
            $isToday = $day->isSameDay($this->world->today);

            foreach ($this->committers($day) as $employee) {
                if (Carbon::parse($employee->doj)->greaterThan($day)) {
                    continue;
                }

                // A handful of past days nobody filled in, as on the live floor.
                if (! $isToday && $this->world->chance(0.03)) {
                    continue;
                }

                [$commitment, $entries] = $this->planDay($employee, $day, $isToday);
                $commitments[] = $commitment;
                $entryPlans[] = $entries;
            }
        }

        $this->world->insert('daily_commitments', $commitments);

        $ids = DB::table('daily_commitments')->orderBy('id')->pluck('id')->all();
        $entries = [];
        $logs = [];

        foreach ($entryPlans as $index => $plan) {
            $commitment = $commitments[$index];

            foreach ($plan as $entry) {
                $entries[] = ['daily_commitment_id' => $ids[$index], ...$entry];
            }

            $logs[] = [
                'daily_commitment_id' => $ids[$index],
                'employee_id' => $commitment['employee_id'],
                'old_stage' => null,
                'new_stage' => $commitment['commitment_stage'],
                'old_amount' => null,
                'new_amount' => $commitment['commitment_amount'],
                'old_count' => null,
                'new_count' => $commitment['commitment_count'],
                'change_type' => 'commitment',
                'note' => 'Morning commitment.',
                'created_at' => $commitment['created_at'],
            ];
        }

        $this->world->insert('daily_commitment_entries', $entries);
        $this->world->insert('daily_commitment_logs', $logs);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}
     */
    private function planDay(object $employee, Carbon $day, bool $isToday): array
    {
        $isCaller = (int) $employee->designation === Employee::DESIGNATION_CALLER;
        $stage = CommitmentStage::from($this->world->weighted($isCaller
            ? ['disbursed' => 48, 'approved' => 22, 'sfl' => 10, 'underwriting' => 8, 'otp' => 12]
            : ['disbursed' => 70, 'approved' => 30]));
        $scale = match ((int) $employee->designation) {
            Employee::DESIGNATION_CALLER => 1,
            Employee::DESIGNATION_TEAM_LEADER => 4,
            default => 9,
        };

        $target = $stage->isCount() ? mt_rand(3, 8) : $this->world->amount(150000, 500000, 25000) * $scale;
        $outcome = $isToday
            ? $this->world->weighted(['in_progress' => 70, 'met' => 30])
            : $this->world->weighted(['met' => 40, 'overachieved' => 15, 'partial' => 20, 'failed' => 25]);

        $entries = $this->entriesFor($stage, $target, $outcome, $day);
        $counting = array_filter($entries, fn (array $entry): bool => $entry['outcome'] === null && CommitmentStage::from($entry['stage'])->rank() >= $stage->rank());
        $below = array_filter($entries, fn (array $entry): bool => $entry['outcome'] === null && CommitmentStage::from($entry['stage'])->rank() < $stage->rank());
        $achieved = $stage->isCount() ? count($counting) : array_sum(array_column($counting, 'amount'));
        $belowTotal = $stage->isCount() ? count($below) : array_sum(array_column($below, 'amount'));
        $result = CommitmentResult::decide((float) $target, (float) $achieved, ! $isToday, (float) ($achieved + $belowTotal));
        $committedAt = $day->copy()->setTime(9, mt_rand(15, 48));
        $submittedAt = $isToday ? null : $day->copy()->setTime(mt_rand(17, 18), mt_rand(0, 29));

        return [[
            'employee_id' => $employee->id,
            'date' => $day->toDateString(),
            'commitment_stage' => $stage->value,
            'commitment_amount' => $stage->isCount() ? 0 : $target,
            'commitment_count' => $stage->isCount() ? $target : 0,
            'current_stage' => $counting === [] ? ($below === [] ? null : $this->highestStage($below)) : $this->highestStage($counting),
            'achievement_amount' => $stage->isCount() ? 0 : $achieved,
            'achievement_count' => $stage->isCount() ? $achieved : 0,
            'below_stage_amount' => $stage->isCount() ? 0 : $belowTotal,
            'below_stage_count' => $stage->isCount() ? $belowTotal : 0,
            'result' => $result->value,
            'submitted_at' => $submittedAt,
            'declaration_note' => $submittedAt ? $this->declarationNote($result) : null,
            'remarks' => null,
            'created_by' => $this->world->userIdFor($employee->id),
            'created_at' => $committedAt,
            'updated_at' => $submittedAt ?? $committedAt,
        ], $entries];
    }

    /**
     * Cases that add up to the planned result for the day.
     *
     * @return array<int, array<string, mixed>>
     */
    private function entriesFor(CommitmentStage $stage, int $target, string $outcome, Carbon $day): array
    {
        $share = match ($outcome) {
            'overachieved' => mt_rand(110, 150) / 100,
            'met' => 1.0,
            'partial' => mt_rand(45, 85) / 100,
            'failed' => mt_rand(0, 60) / 100,
            default => mt_rand(20, 70) / 100,
        };

        $entries = [];
        $atOrAbove = array_values(array_filter(CommitmentStage::ladder(), fn (CommitmentStage $s): bool => $s->rank() >= $stage->rank()));
        $lower = array_values(array_filter(CommitmentStage::ladder(), fn (CommitmentStage $s): bool => $s->rank() < $stage->rank()));

        if ($stage->isCount()) {
            for ($i = 0; $i < (int) round($target * $share); $i++) {
                $entries[] = $this->entry(CommitmentStage::Otp, null, $day);
            }
        } else {
            $goal = (int) round($target * $share / 5000) * 5000;
            $pieces = $goal > 0 ? mt_rand(1, 3) : 0;

            for ($i = 0; $i < $pieces; $i++) {
                $amount = $i === $pieces - 1 ? $goal - array_sum(array_column($entries, 'amount')) : (int) round($goal / $pieces / 5000) * 5000;
                $entries[] = $this->entry($this->world->pick($atOrAbove), max(5000, $amount), $day);
            }

            // A partial day is made up from lower rungs of the ladder.
            if ($outcome === 'partial' && $lower !== []) {
                $entries[] = $this->entry($this->world->pick(array_filter($lower, fn (CommitmentStage $s): bool => ! $s->isCount()) ?: $lower), max(25000, $target - $goal + 25000), $day);
            }

            if ($outcome === 'failed' && $lower !== [] && $this->world->chance(0.5)) {
                $entries[] = $this->entry($this->world->pick($lower), $this->world->amount(25000, 100000), $day);
            }
        }

        if ($this->world->chance(0.12)) {
            $entries[] = $this->entry($stage, $stage->isCount() ? null : $this->world->amount(100000, 300000), $day, $this->world->pick([CommitmentStage::Dropped, CommitmentStage::Rejected]));
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(CommitmentStage $stage, ?int $amount, Carbon $day, ?CommitmentStage $outcome = null): array
    {
        do {
            $mobile = $this->world->mobile();
        } while (isset($this->usedEntryMobiles[$mobile]));

        $this->usedEntryMobiles[$mobile] = true;
        $at = $day->copy()->setTime(mt_rand(10, 17), mt_rand(0, 59));

        return [
            'customer_id' => null,
            'customer_name' => $this->world->faker->firstName().' '.$this->world->faker->lastName(),
            'mobile_no' => $mobile,
            'reference' => $this->world->chance(0.5) ? 'FA'.$day->format('ymd').mt_rand(100000, 999999) : null,
            'stage' => $stage->value,
            'lms_highest_stage' => null,
            'outcome' => $outcome?->value,
            'amount' => $stage->isCount() ? 0 : ($amount ?? 0),
            'remarks' => $outcome ? $this->world->pick(['Customer dropped at the last step.', 'Rejected by bank credit.']) : null,
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function highestStage(array $entries): string
    {
        return collect($entries)
            ->map(fn (array $entry): CommitmentStage => CommitmentStage::from($entry['stage']))
            ->sortByDesc(fn (CommitmentStage $stage): int => $stage->rank() ?? 0)
            ->first()
            ->value;
    }

    private function declarationNote(CommitmentResult $result): string
    {
        return match ($result) {
            CommitmentResult::Overachieved => $this->world->pick(['Two extra disbursals came through today.', 'Beat the commitment — strong closing.']),
            CommitmentResult::Met => $this->world->pick(['Commitment met.', 'All committed files closed.']),
            CommitmentResult::Partial => $this->world->pick(['One file slipped to tomorrow, covered by approvals.', 'Short at stage, made up with logins.']),
            default => $this->world->pick(['Bank server down in the afternoon.', 'Customer postponed disbursal.', 'Two files stuck in credit.']),
        };
    }

    private function seedExpectedOtps(): void
    {
        $rows = [];
        $from = $this->world->monthStart(1);

        foreach ($this->world->workingDays($from, $this->world->today) as $day) {
            foreach ($this->activeIn($day)->where('designation', Employee::DESIGNATION_CALLER) as $caller) {
                $rows[] = [
                    'employee_id' => $caller->id,
                    'date' => $day->toDateString(),
                    'expected_otp' => mt_rand(4, 8),
                    'set_by' => $this->world->userIdFor($caller->superviser_id),
                    'created_at' => $day->copy()->setTime(9, 30),
                    'updated_at' => $day->copy()->setTime(9, 30),
                ];
            }
        }

        $this->world->insert('daily_caller_otps', $rows);
    }

    private function seedInactivityRequests(): void
    {
        $callers = $this->world->employeesWith(Employee::DESIGNATION_CALLER)
            ->reject(fn (object $caller): bool => $caller->id === $this->world->personaEmployeeIds['caller'])
            ->shuffle()
            ->take(3)
            ->values();

        $requests = [
            ['approved', 1, 'Medical leave for most of the month (surgery recovery).', 'Approved — target waived for the month.'],
            ['rejected', 1, 'Requesting target waiver for training week.', 'Training was only 4 days; target stands.'],
            ['pending', 0, 'Extended leave for family wedding — 12 working days.', null],
        ];

        foreach ($requests as $index => [$status, $monthOffset, $reason, $note]) {
            $caller = $callers[$index];
            $requestedAt = $this->world->monthStart($monthOffset)->addDays(mt_rand(1, 4))->setTime(12, 10);

            DB::table('employee_inactivity_requests')->insert([
                'employee_id' => $caller->id,
                'month' => $this->world->monthStart($monthOffset)->toDateString(),
                'requested_by' => $this->world->userIdFor($caller->manager_id),
                'reason' => $reason,
                'status' => $status,
                'reviewed_by' => $status === 'pending' ? null : $this->world->userIdFor($caller->cluster_id),
                'reviewed_at' => $status === 'pending' ? null : $requestedAt->copy()->addDay(),
                'review_note' => $note,
                'created_at' => $requestedAt,
                'updated_at' => $requestedAt,
            ]);
        }
    }

    private function seedOtherBankSupport(): void
    {
        $supportUsers = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'Other Bank Support')
            ->pluck('model_has_roles.model_id');

        foreach (range(self::HISTORY_MONTHS, 0) as $offset) {
            foreach ($supportUsers as $userId) {
                DB::table('other_bank_support_targets')->insert([
                    'user_id' => $userId,
                    'month' => $this->world->monthStart($offset)->toDateString(),
                    'target_amount' => $this->world->pick([4000000, 5000000, 6000000]),
                    'created_at' => $this->world->monthStart($offset),
                    'updated_at' => $this->world->monthStart($offset),
                ]);
            }
        }

        $effective = $this->world->monthStart(self::HISTORY_MONTHS)->toDateString();

        foreach ([[0, 'fixed', 0], [2500000, 'fixed', 5000], [5000000, 'fixed', 12000], [7500000, 'percentage', 0.3]] as [$min, $type, $value]) {
            DB::table('other_bank_incentive_slabs')->insert([
                'effective_month' => $effective,
                'min_achievement' => $min,
                'payout_type' => $type,
                'payout_value' => $value,
                'created_at' => $this->world->monthStart(self::HISTORY_MONTHS),
                'updated_at' => $this->world->monthStart(self::HISTORY_MONTHS),
            ]);
        }

        $pool = DB::table('customers')
            ->where('eligibility_status', 'eligible')
            ->whereNotIn('bank_eligible_for', DemoWorld::IN_HOUSE_BANKS)
            ->where('created_at', '>=', $this->world->monthStart(1))
            ->inRandomOrder()
            ->limit(90)
            ->get();

        $rows = [];

        foreach ($pool as $customer) {
            $stage = match ($customer->journey_status) {
                'underwriting' => 'underwriting',
                'approved' => 'credit_approval',
                'sanctioned' => 'disbursal',
                default => 'sfl',
            };
            $at = Carbon::parse($customer->updated_at)->addHours(mt_rand(1, 20))->min($this->world->now);

            $rows[] = [
                'customer_id' => $customer->id,
                'user_id' => $supportUsers->random(),
                'stage' => $stage,
                'remark' => $this->world->pick([
                    'Profile shared with '.$customer->bank_eligible_for.' RM.',
                    'Bank asked for 6 months bank statement.',
                    'Login done at partner bank.',
                    'Partner bank approved, awaiting disbursal.',
                    'Coordinating e-sign with the customer.',
                ]),
                'created_at' => $at,
                'updated_at' => $at,
            ];
        }

        usort($rows, fn (array $a, array $b): int => $a['created_at'] <=> $b['created_at']);
        $this->world->insert('other_bank_support_remarks', $rows);
    }

    /**
     * Managers, Team Leaders and Callers on the rolls on $day — the seats
     * the target gate and the commitment prompt apply to.
     *
     * @return Collection<int, object>
     */
    private function committers(Carbon $day): Collection
    {
        return $this->activeIn($day)
            ->whereIn('designation', [Employee::DESIGNATION_MANAGER, Employee::DESIGNATION_TEAM_LEADER, Employee::DESIGNATION_CALLER])
            ->values();
    }

    /** @return Collection<int, object> */
    private function activeIn(Carbon $day): Collection
    {
        $monthEnd = $day->copy()->endOfMonth();

        return $this->world->employees->filter(function (object $employee) use ($day, $monthEnd): bool {
            if (Carbon::parse($employee->doj)->greaterThan($monthEnd)) {
                return false;
            }

            return $employee->exit_status !== 'yes' || Carbon::parse($employee->exit_date)->greaterThanOrEqualTo($day);
        });
    }
}
