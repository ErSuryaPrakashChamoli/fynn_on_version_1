<?php

namespace Database\Seeders\DemoEnvironment;

use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\ComplaintPriority;
use App\Models\User;
use App\Services\HelpDesk\ComplaintService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * The Help Desk in the client demo: tickets across every category and
 * status, raised over the last two weeks by the persona logins, so each
 * persona sees a live queue — the caller their own tickets, IT its queue,
 * the manager a team ticket sent up to them, and the Admin an escalation.
 *
 * Goes through ComplaintService (backdated with Carbon::setTestNow) so
 * the thread and bell notifications match what the live app would write.
 */
class HelpDeskSeeder extends Seeder
{
    private DemoWorld $world;

    private ComplaintService $complaints;

    public function run(DemoWorld $world): void
    {
        $this->world = $world;
        $this->complaints = app(ComplaintService::class);

        $persona = $world->personaUserIds;
        $users = User::query()->findMany(array_values($persona))->keyBy('id');

        $by = fn (string $slug): ?User => isset($persona[$slug]) ? $users->get($persona[$slug]) : null;

        $caller = $by('caller');
        $teamLeader = $by('team-leader');
        $manager = $by('manager');
        $it = $by('it');
        $admin = $by('admin');
        $accounts = $by('accounts');

        if (! $caller || ! $it || ! $admin) {
            return;
        }

        $categories = ComplaintCategory::query()->get()->keyBy('name');
        $priorities = ComplaintPriority::query()->get()->keyBy('name');

        $daysAgo = fn (int $days, int $hour = 10): Carbon => $world->today->copy()->subDays($days)->setTime($hour, mt_rand(0, 59));

        // 1. Closed IT ticket, resolved on time.
        $ticket = $this->raiseAt($daysAgo(12), $caller, $categories['Desktop / Laptop'], 'Very slow', $priorities['Medium'], 'Laptop takes 10 minutes to start', 'Since Monday the laptop takes almost ten minutes to reach the login screen. Restarting does not help.');
        Carbon::setTestNow($daysAgo(12, 12));
        $this->complaints->takeUp($ticket, $it);
        Carbon::setTestNow($daysAgo(11, 16));
        $this->complaints->resolve($ticket, $it, 'Cleared start-up programs and replaced the HDD with an SSD from stock.');
        Carbon::setTestNow($daysAgo(11, 17));
        $this->complaints->close($ticket, $caller, 'Much faster now, thank you.');

        // 2. Fynn-On ticket, in progress with IT.
        $ticket = $this->raiseAt($daysAgo(2, 9), $caller, $categories['Fynn-On Application'], 'Wrong data shown', $priorities['High'], 'Follow-up calendar shows a customer twice', 'Ramesh Kulkarni appears on both Tuesday and Thursday even though I rescheduled him to Thursday.');
        Carbon::setTestNow($daysAgo(2, 11));
        $this->complaints->takeUp($ticket, $it);
        $this->complaints->comment($ticket, $it, 'Reproduced on the demo data — looks like the older follow-up row is still being read. Checking with the developers.');

        // 3. Sales ticket sent to the Team Leader, still open.
        if ($teamLeader) {
            $this->raiseAt($daysAgo(1, 15), $caller, $categories['Sales / Team'], 'Lead allocation', $priorities['Medium'], 'Received only 6 leads this week', 'The rest of the team got 15+ leads each from the Pune camp sheet; I received six. Please check the allocation.', $teamLeader);
        }

        // 4. Workspace ticket to Admin, on hold.
        $ticket = $this->raiseAt($daysAgo(4, 10), $caller, $categories['Workspace'], 'Air conditioning / lighting', $priorities['Low'], 'AC not cooling on the 2nd floor', 'The AC above bay 2 blows warm air after 2 PM every day.');
        Carbon::setTestNow($daysAgo(3, 10));
        $this->complaints->takeUp($ticket, $admin);
        $this->complaints->hold($ticket, $admin, 'Vendor visit booked for next Tuesday.');

        // 5. Asset ticket from the manager, new and unassigned in the Admin + IT queue.
        if ($manager) {
            $this->raiseAt($daysAgo(0, 9), $manager, $categories['Asset'], 'Asset not issued', $priorities['Medium'], 'New joiner has no headset', 'Priya joined on Monday and is sharing a headset. Please issue one from stock.');
        }

        // 6. HR / Payroll ticket to Accounts, resolved and awaiting the raiser.
        if ($accounts) {
            $ticket = $this->raiseAt($daysAgo(3, 11), $caller, $categories['HR / Payroll'], 'Reimbursement', $priorities['Low'], 'Conveyance claim for August pending', 'Submitted the August conveyance claim on the 2nd; it has not been credited.');
            Carbon::setTestNow($daysAgo(1, 12));
            $this->complaints->takeUp($ticket, $accounts);
            $this->complaints->resolve($ticket, $accounts, 'Processed with the September payroll — credited on the 30th.');
        }

        // 7. Critical IT ticket, overdue and unassigned — escalates to the Admin below.
        $this->raiseAt($daysAgo(1, 8), $manager ?? $caller, $categories['IT'], 'Internet / network down', $priorities['Critical'], 'Dialer line dropping every few minutes', 'The whole floor loses the dialer connection for 30 seconds every ten minutes since this morning.');

        // 8. Reopened Fynn-On ticket.
        $ticket = $this->raiseAt($daysAgo(8, 10), $caller, $categories['Fynn-On Application'], 'Report / export problem', $priorities['Medium'], 'Lead export is missing the city column', 'The Excel export from Leads has no City column although the listing shows it.');
        Carbon::setTestNow($daysAgo(7, 10));
        $this->complaints->takeUp($ticket, $it);
        $this->complaints->resolve($ticket, $it, 'Export updated to include City.');
        Carbon::setTestNow($daysAgo(6, 9));
        $this->complaints->reopen($ticket, $caller, 'The column is there now but every value is blank.');

        Carbon::setTestNow($world->now);

        // Let the app's own runner escalate whatever is past its deadline.
        Artisan::call('complaints:escalate');
    }

    private function raiseAt(Carbon $at, User $raiser, ComplaintCategory $category, string $reason, ComplaintPriority $priority, string $subject, string $description, ?User $supervisor = null): Complaint
    {
        Carbon::setTestNow($at);

        $data = [
            'category_id' => $category->id,
            'reason_id' => $category->reasons()->where('name', $reason)->value('id'),
            'priority_id' => $priority->id,
            'subject' => $subject,
            'description' => $description,
        ];

        if ($category->routesToSupervisor()) {
            // The persona picked must really be above the raiser in the
            // reporting tree; otherwise fall back to the nearest boss.
            $options = $this->complaints->supervisorOptionsFor($raiser);
            $data['escalate_to'] = $supervisor && $options->has($supervisor->getKey())
                ? $supervisor->getKey()
                : $options->keys()->first();
        }

        return $this->complaints->raise($raiser, $data);
    }
}
