<?php

namespace Database\Seeders\DemoEnvironment;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use Filament\Notifications\DatabaseNotification;
use Filament\Notifications\Notification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Everything around the work itself: attendance (login sessions), the
 * activity log, bell notifications, journey continuity (backup cover,
 * emergency takeovers, reassignments, SLA breaches), disbursal documents
 * and a few extra performance ratios.
 */
class OperationsSeeder extends Seeder
{
    private DemoWorld $world;

    public function run(DemoWorld $world): void
    {
        $this->world = $world;

        $this->seedLoginSessions();
        $this->seedDisbursalDocuments();
        $this->seedContinuity();
        $this->seedActivityLog();
        $this->seedNotifications();
        $this->seedPerformanceRatios();

        // Let the app's own SLA check open (and escalate) breaches on the
        // live pipeline, then add a history of breaches since resolved.
        Artisan::call('journey:check-sla-breaches');
        $this->seedResolvedBreaches();
    }

    /**
     * One session per working day per person, which is also what the
     * commitment dashboards read as present / absent.
     */
    private function seedLoginSessions(): void
    {
        $users = DB::table('users')->where('is_active', true)->get(['id', 'employee_id']);
        $rows = [];
        $agents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0.0.0 Safari/537.36 Edg/127.0.0.0',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
        ];

        foreach ($users as $user) {
            $employee = $user->employee_id ? $this->world->employee($user->employee_id) : null;
            $from = $employee ? Carbon::parse($employee->doj)->max($this->world->monthStart(2)) : $this->world->monthStart(2);
            $to = $this->world->today->copy()->subDay();

            if ($employee?->exit_status === 'yes') {
                $to = $to->min(Carbon::parse($employee->exit_date));
            }

            foreach ($this->world->workingDays($from, $to) as $day) {
                if ($this->world->chance(0.05)) {
                    continue;
                }

                $login = $day->copy()->setTime(9, mt_rand(5, 50), mt_rand(0, 59));
                $logout = $day->copy()->setTime(mt_rand(18, 19), mt_rand(0, 59));
                $timedOut = $this->world->chance(0.2);

                $rows[] = [
                    'user_id' => $user->id,
                    'employee_id' => $user->employee_id,
                    'session_id' => Str::random(40),
                    'login_at' => $login,
                    'logout_at' => $logout,
                    'last_seen_at' => $logout->copy()->subMinutes(mt_rand(0, 8)),
                    'last_activity_at' => $timedOut ? $logout->copy()->subMinutes(30) : $logout,
                    'screen_time_seconds' => mt_rand(18000, 30000),
                    'ip_address' => '10.20.'.mt_rand(1, 20).'.'.mt_rand(2, 250),
                    'user_agent' => $this->world->pick($agents),
                    'logout_reason' => $timedOut ? 'session_timeout' : 'logout',
                    'created_at' => $login,
                    'updated_at' => $logout,
                ];
            }
        }

        usort($rows, fn (array $a, array $b): int => $a['login_at'] <=> $b['login_at']);
        $this->world->insert('user_login_sessions', $rows);
    }

    private function seedDisbursalDocuments(): void
    {
        $path = 'disbursal-documents/demo-disbursal-letter.pdf';
        Storage::disk('public')->put($path, $this->world->samplePdf('Disbursal Letter'));

        $rows = DB::table('customers')
            ->where('documents_submitted', true)
            ->get(['id', 'employee_id', 'disbursal_date', 'updated_at'])
            ->map(fn (object $customer): array => [
                'customer_id' => $customer->id,
                'document_type' => 'Disbursal Document',
                'document_name' => 'Disbursal Letter.pdf',
                'document_path' => $path,
                'mime_type' => 'application/pdf',
                'file_size' => mt_rand(90000, 900000),
                'uploaded_by' => $this->world->userIdFor($this->world->employee($customer->employee_id)->manager_id),
                'is_latest' => true,
                'created_at' => $customer->updated_at,
                'updated_at' => $customer->updated_at,
            ])
            ->all();

        $this->world->insert('customer_documents', $rows);
    }

    private function seedContinuity(): void
    {
        $persona = $this->world->personaEmployeeIds;
        $manager = $this->world->employee($persona['manager']);
        $peerManager = $this->world->employeesWith(Employee::DESIGNATION_MANAGER)
            ->first(fn (object $m): bool => $m->cluster_id === $manager->cluster_id && $m->id !== $manager->id);
        $teamLeader = $this->world->employee($persona['team-leader']);
        $peerLeader = $this->world->employeesWith(Employee::DESIGNATION_TEAM_LEADER)
            ->first(fn (object $t): bool => $t->manager_id === $manager->id && $t->id !== $teamLeader->id);
        $clusterUser = $this->world->personaUserIds['cluster-manager'];
        $now = $this->world->now;
        $allModules = ['document_verification', 'approval', 'bank_processing', 'disbursal_processing', 'customer_follow_up'];

        $delegations = [
            [$peerManager->id, $manager->id, $now->copy()->subDays(3)->setTime(9, 0), $now->copy()->addDays(4)->setTime(20, 0), $allModules, 'existing_and_new', 'active', 'Annual leave — Priya covers the team.', null],
            [$teamLeader->id, $peerLeader->id, $now->copy()->addDays(6)->setTime(9, 0), $now->copy()->addDays(9)->setTime(20, 0), ['document_verification', 'approval'], 'existing', 'active', 'Planned leave for a family function.', null],
            [$peerLeader->id, $manager->id, $this->world->monthStart(1)->addDays(10)->setTime(9, 0), $this->world->monthStart(1)->addDays(14)->setTime(20, 0), ['approval', 'bank_processing'], 'existing_and_new', 'active', 'Medical leave.', null],
            [$manager->id, $peerManager->id, $this->world->monthStart(1)->addDays(20)->setTime(9, 0), $this->world->monthStart(1)->addDays(22)->setTime(20, 0), $allModules, 'existing', 'cancelled', 'Training programme at head office.', 'Training rescheduled.'],
        ];

        foreach ($delegations as [$from, $to, $start, $end, $modules, $coverage, $status, $reason, $cancelled]) {
            $id = DB::table('customer_journey_delegations')->insertGetId([
                'delegating_manager_id' => $from,
                'acting_manager_id' => $to,
                'start_at' => $start,
                'end_at' => $end,
                'modules' => json_encode($modules),
                'coverage_type' => $coverage,
                'scope_type' => 'hierarchy_branch',
                'access_type' => 'temporary_delegation',
                'is_admin_override' => false,
                'reason' => $reason,
                'status' => $status,
                'requires_approval' => false,
                'created_by' => $clusterUser,
                'cancelled_by' => $cancelled ? $clusterUser : null,
                'cancelled_at' => $cancelled ? $start->copy()->subDays(2) : null,
                'cancellation_reason' => $cancelled,
                'created_at' => $start->copy()->subDays(mt_rand(2, 5))->min($now),
                'updated_at' => $start->copy()->subDays(1)->min($now),
            ]);

            $this->logActivity('journey', 'Temporary journey access delegated', 'App\Models\CustomerJourneyDelegation', $id, $clusterUser, $start->copy()->subDays(2)->min($now));
        }

        // Emergency takeovers by the Cluster Manager on files stuck in the branch.
        $stuck = Customer::query()
            ->whereIn('journey_status', ['underwriting', 'approved'])
            ->whereIn('assign_to', $this->world->employees->where('cluster_id', $persona['cluster-manager'])->pluck('id'))
            ->limit(3)
            ->get();

        foreach ($stuck as $index => $customer) {
            $startedAt = $now->copy()->subDays($index + 1)->setTime(11, 30)->min($now);
            $ended = $index === 2;

            $takeoverId = DB::table('journey_takeovers')->insertGetId([
                'customer_id' => $customer->id,
                'original_manager_id' => $this->world->employee($customer->assign_to)->manager_id,
                'takeover_by_id' => $persona['cluster-manager'],
                'takeover_type' => ['sla_breach', 'manager_on_leave', 'escalation'][$index],
                'reason' => ['File breached the approval SLA twice.', 'Manager on leave, customer waiting on sanction.', 'Customer escalated delay to the branch.'][$index],
                'modules' => json_encode(['approval', 'bank_processing']),
                'status' => $ended ? 'ended' : 'active',
                'started_at' => $startedAt,
                'ended_at' => $ended ? $startedAt->copy()->addHours(20)->min($now) : null,
                'created_by' => $clusterUser,
                'ended_by' => $ended ? $clusterUser : null,
                'created_at' => $startedAt,
                'updated_at' => $startedAt,
            ]);

            $this->audit($customer, 'Emergency takeover started', 'emergency_takeover', $persona['cluster-manager'], $clusterUser, $startedAt, takeoverId: $takeoverId);
        }

        // Files left behind by callers who have exited, handed to a Team Leader.
        $orphans = Customer::query()
            ->whereIn('assign_to', $this->world->employees->where('exit_status', 'yes')->pluck('id'))
            ->whereNotIn('journey_status', ['sanctioned', 'dropped', 'not_approved'])
            ->limit(4)
            ->get();

        foreach ($orphans as $customer) {
            $previous = $this->world->employee($customer->assign_to);
            $newOwner = $previous->superviser_id;
            $at = Carbon::parse($previous->exit_date)->addDays(1)->setTime(12, 0)->min($now);
            $by = $this->world->userIdFor($previous->cluster_id);

            DB::table('customer_reassignments')->insert([
                'customer_id' => $customer->id,
                'previous_owner_id' => $previous->id,
                'new_owner_id' => $newOwner,
                'reassigned_by' => $by,
                'reason' => 'Caller has left the company; file moved to the Team Leader.',
                'reassigned_at' => $at,
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            DB::table('customers')->where('id', $customer->id)->update(['assign_to' => $newOwner]);
            $this->audit($customer, 'Customer permanently reassigned', 'permanent_reassignment', $newOwner, $by, $at);
            $this->logActivity('journey', 'Customer reassigned to a new owner', Customer::class, $customer->id, $by, $at);
        }
    }

    /**
     * Customer is the one model that logs itself; the most recent month's
     * creations and stage moves are recorded with the person who made them.
     */
    private function seedActivityLog(): void
    {
        $recent = DB::table('customers')->where('created_at', '>=', $this->world->today->copy()->subDays(30))->get();
        $rows = [];

        foreach ($recent as $customer) {
            $owner = $this->world->userIdFor($customer->employee_id);

            $rows[] = [
                'log_name' => 'default',
                'description' => 'created',
                'subject_type' => Customer::class,
                'subject_id' => $customer->id,
                'event' => 'created',
                'causer_type' => $owner ? User::class : null,
                'causer_id' => $owner,
                'attribute_changes' => json_encode(['attributes' => [
                    'id' => $customer->id,
                    'customer_name' => $customer->customer_name,
                    'mobile_no' => $customer->mobile_no,
                    'pan_number' => $customer->pan_number,
                    'loan_applied' => $customer->loan_applied,
                    'bank_eligible_for' => $customer->bank_eligible_for,
                    'eligibility_status' => $customer->eligibility_status,
                    'journey_status' => $customer->eligibility_status === 'eligible' ? 'sfl' : 'not_started',
                ]]),
                'properties' => json_encode([]),
                'created_at' => $customer->created_at,
                'updated_at' => $customer->created_at,
            ];
        }

        $moves = DB::table('customer_stage_histories')
            ->where('stage_name', 'like', '% Stage')
            ->where('created_at', '>=', $this->world->today->copy()->subDays(30))
            ->get();

        foreach ($moves as $move) {
            $from = Str::of($move->stage_name)->before(' Stage')->lower()->replace(' ', '_')->toString();
            $to = Str::of($move->status_value)->after('Moved to ')->lower()->replace(' ', '_')->toString();

            $rows[] = [
                'log_name' => 'default',
                'description' => 'updated',
                'subject_type' => Customer::class,
                'subject_id' => $move->customer_id,
                'event' => 'updated',
                'causer_type' => $move->user_id ? User::class : null,
                'causer_id' => $move->user_id,
                'attribute_changes' => json_encode(['attributes' => ['journey_status' => $to], 'old' => ['journey_status' => $from]]),
                'properties' => json_encode([]),
                'created_at' => $move->created_at,
                'updated_at' => $move->created_at,
            ];
        }

        usort($rows, fn (array $a, array $b): int => $a['created_at'] <=> $b['created_at']);
        $this->world->insert('activity_log', $rows);
    }

    private function seedNotifications(): void
    {
        $persona = $this->world->personaUserIds;
        $pan = DB::table('customer_pan_requests')->where('status', 'approved')->first();

        $messages = [
            [$persona['caller'], 'PAN request approved', 'Your request has been approved. Click Continue to create the application.', 'heroicon-o-check-circle', 'success', 2],
            [$persona['caller'], 'Follow-ups due today', 'You have follow-ups scheduled for today. Open the calendar to plan your calls.', 'heroicon-o-calendar-days', 'info', 0],
            [$persona['team-leader'], 'New PAN request', ($pan?->requested_by_name ?? 'A caller').' requested approval for a PAN already on another file.', 'heroicon-o-identification', 'warning', 1],
            [$persona['manager'], 'SLA breach escalated', 'Two files in your team crossed the approval SLA.', 'heroicon-o-exclamation-triangle', 'danger', 0],
            [$persona['manager'], 'Backup cover active', 'You are covering a colleague’s team this week.', 'heroicon-o-shield-check', 'info', 3],
            [$persona['cluster-manager'], 'Emergency takeover started', 'You took over a file stuck in approval.', 'heroicon-o-bolt', 'warning', 1],
            [$persona['mis'], 'OCR document processed', 'Pune IT park camp — lead sheet: rows extracted and ready for review.', 'heroicon-o-document-check', 'success', 2],
            [$persona['mis'], 'OCR document failed', 'Weekend camp — handwritten sheet could not be read.', 'heroicon-o-x-circle', 'danger', 4],
            [$persona['accounts'], 'MIS batch imported', 'This month’s bank MIS has been reconciled. Files are ready for Accounts.', 'heroicon-o-banknotes', 'success', 1],
            [$persona['admin'], 'MIS batch imported', 'This month’s bank MIS has been imported and matched.', 'heroicon-o-arrow-up-tray', 'success', 1],
            [$persona['admin'], 'Inactivity request pending', 'A target waiver request is waiting for review.', 'heroicon-o-clock', 'warning', 0],
        ];

        foreach ($messages as [$userId, $title, $body, $icon, $status, $daysAgo]) {
            $at = $this->world->now->copy()->subDays($daysAgo)->subMinutes(mt_rand(10, 300));

            DB::table('notifications')->insert([
                'id' => (string) Str::uuid(),
                'type' => DatabaseNotification::class,
                'notifiable_type' => User::class,
                'notifiable_id' => $userId,
                'data' => json_encode(Notification::make()->title($title)->body($body)->icon($icon)->status($status)->getDatabaseMessage()),
                'read_at' => $daysAgo > 2 ? $at->copy()->addHour() : null,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }
    }

    private function seedPerformanceRatios(): void
    {
        $next = (int) DB::table('performance_metric_ratios')->max('sort_order');

        foreach ([
            ['Target Achievement', 'count_achievement', 'target_amount', 'percentage'],
            ['Attendance', 'present_days', 'working_days', 'percentage'],
            ['Eligible OTP Share', 'eligible_otp_count', 'otp_count', 'percentage'],
            ['Average Ticket Size', 'disbursal_amount', 'disbursal_count', 'decimal'],
        ] as [$name, $numerator, $denominator, $format]) {
            DB::table('performance_metric_ratios')->insert([
                'name' => $name,
                'numerator_key' => $numerator,
                'denominator_key' => $denominator,
                'format' => $format,
                'is_active' => true,
                'sort_order' => ++$next,
                'created_at' => $this->world->monthStart(2),
                'updated_at' => $this->world->monthStart(2),
            ]);
        }
    }

    /**
     * Breaches on files that have since moved on — the history the SLA
     * screen shows beside today's open ones.
     */
    private function seedResolvedBreaches(): void
    {
        $customers = DB::table('customers')
            ->where('disbursal_status', 'disbursed')
            ->where('disbursal_date', '>=', $this->world->monthStart(1)->toDateString())
            ->inRandomOrder()
            ->limit(60)
            ->get();

        $rows = [];

        foreach ($customers as $customer) {
            $module = $this->world->weighted(['document_verification' => 50, 'approval' => 30, 'bank_processing' => 20]);
            $entered = Carbon::parse($customer->created_at);
            $escalated = $this->world->chance(0.35);

            $rows[] = [
                'customer_id' => $customer->id,
                'module' => $module,
                'stage_entered_at' => $entered,
                'reminder_sent_at' => $entered->copy()->addMinutes((int) config("journey_sla.reminder_minutes.{$module}", 60)),
                'escalated_at' => $escalated ? $entered->copy()->addMinutes((int) config("journey_sla.escalation_minutes.{$module}", 120)) : null,
                'escalated_to_employee_id' => $escalated ? $this->world->employee($customer->employee_id)->cluster_id : null,
                'status' => 'resolved',
                'resolved_at' => $entered->copy()->addHours(mt_rand(4, 40)),
                'created_at' => $entered,
                'updated_at' => $entered,
            ];
        }

        $this->world->insert('customer_sla_breaches', $rows);
    }

    private function audit(Customer $customer, string $action, string $accessType, ?int $actingEmployeeId, ?int $userId, Carbon $at, ?int $takeoverId = null): void
    {
        DB::table('customer_journey_audits')->insert([
            'customer_id' => $customer->id,
            'journey_stage' => $customer->journey_status ?? 'unknown',
            'module' => null,
            'action' => $action,
            'access_type' => $accessType,
            'original_owner_id' => $customer->assign_to,
            'acting_employee_id' => $actingEmployeeId,
            'is_admin_override' => false,
            'takeover_id' => $takeoverId,
            'performed_by' => $userId,
            'performed_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function logActivity(string $log, string $description, string $subjectType, int $subjectId, ?int $causerId, Carbon $at): void
    {
        DB::table('activity_log')->insert([
            'log_name' => $log,
            'description' => $description,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'causer_type' => $causerId ? User::class : null,
            'causer_id' => $causerId,
            'properties' => json_encode([]),
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }
}
