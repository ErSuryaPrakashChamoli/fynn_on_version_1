<?php

namespace Tests\Feature;

use App\Enums\ComplaintStatus;
use App\Filament\Resources\ComplaintCategories\ComplaintCategoryResource;
use App\Filament\Resources\ComplaintCategories\Pages\CreateComplaintCategory;
use App\Filament\Resources\ComplaintPriorities\ComplaintPriorityResource;
use App\Filament\Resources\Complaints\ComplaintResource;
use App\Filament\Resources\Complaints\Pages\CreateComplaint;
use App\Filament\Resources\Complaints\Pages\ListComplaints;
use App\Filament\Resources\Complaints\Pages\ViewComplaint;
use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\ComplaintPriority;
use App\Models\Employee;
use App\Models\User;
use App\Services\HelpDesk\ComplaintEscalationService;
use App\Services\HelpDesk\ComplaintService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Help Desk: anyone raises a complaint or query; it is routed to the
 * handling team (by role) or to a supervisor the raiser picks; the
 * priority's Admin-set SLA stamps the deadline; past the deadline the
 * ticket escalates up the handler's reporting line.
 */
class HelpDeskComplaintTest extends TestCase
{
    use RefreshDatabase;

    private Employee $manager;

    private Employee $teamLeader;

    private Employee $caller;

    private User $managerUser;

    private User $teamLeaderUser;

    private User $callerUser;

    private User $itUser;

    private User $adminUser;

    private ComplaintService $complaints;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Manager', 'Team Leader', 'Caller', 'IT', 'Accounts'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->travelTo(Carbon::parse('2026-09-26 10:00:00'));

        $this->manager = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER, 'exit_status' => 'no', 'emp_name' => 'Meera Manager']);
        $this->teamLeader = Employee::factory()->create(['designation' => Employee::DESIGNATION_TEAM_LEADER, 'manager_id' => $this->manager->id, 'exit_status' => 'no', 'emp_name' => 'Tarun Leader']);
        $this->caller = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'superviser_id' => $this->teamLeader->id, 'manager_id' => $this->manager->id, 'exit_status' => 'no', 'emp_name' => 'Ravi Caller']);

        $this->managerUser = User::factory()->create(['employee_id' => $this->manager->id, 'name' => 'Meera Manager']);
        $this->managerUser->assignRole('Manager');
        $this->teamLeaderUser = User::factory()->create(['employee_id' => $this->teamLeader->id, 'name' => 'Tarun Leader']);
        $this->teamLeaderUser->assignRole('Team Leader');
        $this->callerUser = User::factory()->create(['employee_id' => $this->caller->id, 'name' => 'Ravi Caller']);
        $this->callerUser->assignRole('Caller');
        $this->itUser = User::factory()->create(['name' => 'Ishaan IT']);
        $this->itUser->assignRole('IT');
        $this->adminUser = User::factory()->create(['name' => 'Asha Admin']);
        $this->adminUser->assignRole('Admin');

        $this->complaints = app(ComplaintService::class);
    }

    public function test_the_migration_seeds_the_default_categories_reasons_and_priorities(): void
    {
        $this->assertSame(8, ComplaintCategory::query()->count());
        $this->assertSame(4, ComplaintPriority::query()->count());
        $this->assertSame(['IT'], ComplaintCategory::query()->where('name', 'IT')->firstOrFail()->handlerRoles());
        $this->assertTrue(ComplaintCategory::query()->where('name', 'Sales / Team')->firstOrFail()->routesToSupervisor());
        $this->assertGreaterThan(0, ComplaintCategory::query()->where('name', 'IT')->firstOrFail()->reasons()->count());
        $this->assertSame(10, $this->priority('Critical')->resolve_within_minutes);
        $this->assertSame(['Low', 'Medium', 'High', 'Critical'], ComplaintPriority::query()->ordered()->pluck('name')->all());
        $this->assertSame('2 days', $this->priority('Low')->slaLabel());
        $this->assertSame('a day', $this->priority('Medium')->slaLabel());
        $this->assertSame('an hour', $this->priority('High')->slaLabel());
        $this->assertSame('10 minutes', $this->priority('Critical')->slaLabel());
    }

    public function test_a_caller_raises_an_it_ticket_which_lands_in_the_it_queue_with_the_sla_deadline(): void
    {
        $ticket = $this->raise($this->callerUser, 'IT', 'High');

        $this->assertSame('TKT-'.str_pad((string) $ticket->id, 6, '0', STR_PAD_LEFT), $ticket->ticket_no);
        $this->assertSame(ComplaintStatus::Open, $ticket->status);
        $this->assertSame('IT', $ticket->handler_role);
        $this->assertNull($ticket->assigned_to);
        $this->assertTrue($ticket->due_at->eq(now()->addMinutes(60)));

        $this->assertSame(1, $this->itUser->notifications()->count());
        $this->assertSame('help_desk', $this->itUser->notifications()->first()->category);
        $this->assertSame(0, $this->teamLeaderUser->notifications()->count());
        $this->assertStringContainsString('Ticket raised under IT', $ticket->comments()->first()->body);
    }

    public function test_a_sales_ticket_goes_to_the_supervisor_the_caller_picks(): void
    {
        $options = $this->complaints->supervisorOptionsFor($this->callerUser);

        $this->assertSame([$this->teamLeaderUser->id, $this->managerUser->id], $options->keys()->all());

        $ticket = $this->raise($this->callerUser, 'Sales / Team', 'Medium', ['escalate_to' => $this->managerUser->id]);

        $this->assertNull($ticket->handler_role);
        $this->assertSame($this->managerUser->id, $ticket->assigned_to);
        $this->assertSame(1, $this->managerUser->notifications()->count());
        $this->assertSame(0, $this->teamLeaderUser->notifications()->count());
    }

    public function test_a_sales_ticket_cannot_be_sent_to_somebody_outside_the_callers_reporting_line(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->raise($this->callerUser, 'Sales / Team', 'Medium', ['escalate_to' => $this->itUser->id]);
    }

    public function test_every_role_reaches_the_ticket_screen_but_only_admin_reaches_the_settings(): void
    {
        foreach ([$this->callerUser, $this->teamLeaderUser, $this->itUser, $this->adminUser] as $user) {
            $this->actingAs($user);
            $this->assertTrue(ComplaintResource::canAccess(), $user->name.' should reach the Help Desk');
            $this->assertTrue(ComplaintResource::canCreate());
        }

        $this->actingAs($this->callerUser);
        $this->assertFalse(ComplaintCategoryResource::canAccess());
        $this->assertFalse(ComplaintPriorityResource::canAccess());

        $this->actingAs($this->adminUser);
        $this->assertTrue(ComplaintCategoryResource::canAccess());
        $this->assertTrue(ComplaintPriorityResource::canAccess());
    }

    public function test_the_sidebar_shows_the_help_desk_to_a_non_admin_and_the_settings_only_to_the_admin(): void
    {
        $this->actingAs($this->itUser)
            ->followingRedirects()
            ->get('/admin')
            ->assertOk()
            ->assertSee(ComplaintResource::getUrl('index'), escape: false)
            ->assertDontSee(ComplaintCategoryResource::getUrl('index'), escape: false);

        $this->actingAs($this->adminUser)
            ->followingRedirects()
            ->get('/admin')
            ->assertOk()
            ->assertSee(ComplaintCategoryResource::getUrl('index'), escape: false)
            ->assertSee(ComplaintPriorityResource::getUrl('index'), escape: false);
    }

    public function test_visibility_is_the_raiser_the_handling_team_the_assignee_and_the_admin(): void
    {
        $itTicket = $this->raise($this->callerUser, 'IT', 'Medium');
        $salesTicket = $this->raise($this->callerUser, 'Sales / Team', 'Medium', ['escalate_to' => $this->teamLeaderUser->id]);
        $othersTicket = $this->raise($this->managerUser, 'Workspace', 'Low');

        $this->assertVisible($this->callerUser, [$itTicket, $salesTicket]);
        $this->assertVisible($this->itUser, [$itTicket]);
        $this->assertVisible($this->teamLeaderUser, [$salesTicket]);
        // The Manager follows the ticket their Team Leader holds, but not the caller's IT ticket.
        $this->assertVisible($this->managerUser, [$salesTicket, $othersTicket]);
        $this->assertFalse($this->complaints->canHandle($salesTicket, $this->managerUser));
        $this->assertVisible($this->adminUser, [$itTicket, $salesTicket, $othersTicket]);
    }

    public function test_the_handling_team_works_the_ticket_and_the_raiser_closes_it(): void
    {
        $ticket = $this->raise($this->callerUser, 'IT', 'Medium');

        $this->complaints->takeUp($ticket, $this->itUser);
        $this->assertSame(ComplaintStatus::InProgress, $ticket->status);
        $this->assertSame($this->itUser->id, $ticket->assigned_to);
        $this->assertNotNull($ticket->first_response_at);

        $this->complaints->hold($ticket, $this->itUser, 'Waiting for a spare part.');
        $this->assertSame(ComplaintStatus::OnHold, $ticket->status);

        $this->complaints->resume($ticket, $this->itUser);
        $this->complaints->resolve($ticket, $this->itUser, 'Replaced the keyboard.');
        $this->assertSame(ComplaintStatus::Resolved, $ticket->status);
        $this->assertSame('Replaced the keyboard.', $ticket->resolution_note);
        $this->assertTrue($this->callerUser->notifications()->get()->contains(fn ($notification): bool => str_contains($notification->data['title'], 'resolved')));

        $this->complaints->close($ticket, $this->callerUser);
        $this->assertSame(ComplaintStatus::Closed, $ticket->status);
        $this->assertSame($this->callerUser->id, $ticket->closed_by);

        $this->assertStringContainsString('closed the ticket', $ticket->comments()->latest('id')->first()->body);
    }

    public function test_somebody_outside_the_handling_team_cannot_work_the_ticket(): void
    {
        $ticket = $this->raise($this->callerUser, 'IT', 'Medium');

        $this->expectException(AuthorizationException::class);

        $this->complaints->resolve($ticket, $this->teamLeaderUser, 'Not mine to fix.');
    }

    public function test_only_the_raiser_or_admin_closes_and_the_raiser_may_not_resolve_their_own_ticket(): void
    {
        $ticket = $this->raise($this->callerUser, 'IT', 'Medium');
        $this->complaints->resolve($ticket, $this->itUser, 'Done.');

        try {
            $this->complaints->close($ticket, $this->itUser);
            $this->fail('The handler closed the ticket.');
        } catch (AuthorizationException) {
        }

        try {
            $this->complaints->resolve($this->raise($this->callerUser, 'IT', 'Medium'), $this->callerUser, 'Fixed it myself.');
            $this->fail('The raiser resolved their own team ticket.');
        } catch (AuthorizationException) {
        }

        $this->complaints->close($ticket, $this->adminUser, 'Closing on behalf.');
        $this->assertSame(ComplaintStatus::Closed, $ticket->status);
    }

    public function test_reopening_restarts_the_sla_and_clears_the_escalation(): void
    {
        $ticket = $this->raise($this->callerUser, 'IT', 'High');
        $ticket->forceFill(['escalation_level' => 1, 'escalated_to' => $this->adminUser->id, 'escalated_at' => now()])->save();
        $this->complaints->resolve($ticket, $this->itUser, 'Done.');

        $this->travel(2)->days();
        $this->complaints->reopen($ticket, $this->callerUser, 'Still broken.');

        $this->assertSame(ComplaintStatus::Open, $ticket->status);
        $this->assertSame(1, $ticket->reopened_count);
        $this->assertSame(0, $ticket->escalation_level);
        $this->assertNull($ticket->escalated_to);
        $this->assertNull($ticket->resolution_note);
        $this->assertTrue($ticket->due_at->eq(now()->addMinutes(60)));
    }

    public function test_the_admin_changes_the_priority_and_the_deadline_follows_from_when_it_was_raised(): void
    {
        $ticket = $this->raise($this->callerUser, 'IT', 'Low');
        $raisedAt = now()->copy();

        $this->travel(3)->hours();
        $this->complaints->reprioritise($ticket, $this->adminUser, $this->priority('Critical'));

        $this->assertSame($this->priority('Critical')->id, $ticket->priority_id);
        $this->assertTrue($ticket->due_at->eq($raisedAt->addMinutes(10)));

        $this->expectException(AuthorizationException::class);
        $this->complaints->reprioritise($ticket, $this->itUser, $this->priority('Low'));
    }

    public function test_an_overdue_ticket_escalates_to_the_assignees_boss_then_the_boss_above_each_once(): void
    {
        $ticket = $this->raise($this->callerUser, 'Sales / Team', 'High', ['escalate_to' => $this->teamLeaderUser->id]);
        $this->managerUser->notifications()->delete();
        $escalations = app(ComplaintEscalationService::class);

        // High = within an hour: nothing at 50 minutes, level 1 at 65.
        $this->travel(50)->minutes();
        $this->assertSame(0, $escalations->escalateOverdue());

        $this->travel(15)->minutes();
        $this->assertSame(1, $escalations->escalateOverdue());
        $this->assertSame(0, $escalations->escalateOverdue());

        $ticket->refresh();
        $this->assertSame(1, $ticket->escalation_level);
        $this->assertSame($this->managerUser->id, $ticket->escalated_to);
        $this->assertStringContainsString('Escalation: ticket '.$ticket->ticket_no, $this->managerUser->notifications()->first()->data['title']);
        $this->assertStringContainsString('escalated to Meera Manager', $this->teamLeaderUser->notifications()->latest()->first()->data['title']);
        $this->assertSame(0, $this->adminUser->notifications()->count());

        // A second full SLA window later: nobody above the Manager, so the Admins.
        $this->travel(65)->minutes();
        $this->assertSame(1, $escalations->escalateOverdue());

        $ticket->refresh();
        $this->assertSame(2, $ticket->escalation_level);
        $this->assertSame($this->adminUser->id, $ticket->escalated_to);
        $this->assertSame(1, $this->adminUser->notifications()->count());
        $this->assertTrue($this->complaints->canView($ticket, $this->managerUser));

        $this->travel(5)->days();
        $this->assertSame(0, $escalations->escalateOverdue());
    }

    public function test_an_overdue_team_ticket_nobody_took_up_escalates_to_the_admin(): void
    {
        $ticket = $this->raise($this->callerUser, 'IT', 'Critical');

        // Critical = within 10 minutes: overdue, but inside the second window.
        $this->travel(15)->minutes();
        $this->assertSame(1, app(ComplaintEscalationService::class)->escalateOverdue());

        $ticket->refresh();
        $this->assertSame(1, $ticket->escalation_level);
        $this->assertSame($this->adminUser->id, $ticket->escalated_to);
        $this->assertStringContainsString('Escalated (level 1) to Asha Admin', $ticket->comments()->latest('id')->first()->body);
    }

    public function test_a_resolved_ticket_never_escalates(): void
    {
        $ticket = $this->raise($this->callerUser, 'IT', 'Critical');
        $this->complaints->resolve($ticket, $this->itUser, 'Done.');

        $this->travel(2)->days();
        $this->assertSame(0, app(ComplaintEscalationService::class)->escalateOverdue());
        $this->assertSame(0, $ticket->fresh()->escalation_level);
    }

    public function test_internal_comments_stay_hidden_from_the_raiser(): void
    {
        $ticket = $this->raise($this->callerUser, 'IT', 'Medium');
        $this->callerUser->notifications()->delete();

        $this->complaints->comment($ticket, $this->itUser, 'Looks like a driver issue.', internal: true);
        $this->assertSame(0, $this->callerUser->notifications()->count());

        $this->complaints->comment($ticket, $this->itUser, 'We will replace it tomorrow.');
        $this->assertSame(1, $this->callerUser->notifications()->count());

        // A raiser cannot post an internal note; it is stored as public.
        $comment = $this->complaints->comment($ticket, $this->callerUser, 'Thanks.', internal: true);
        $this->assertFalse($comment->is_internal);

        $this->assertSame(2, $ticket->comments()->visibleToRaiser()->where('type', 'comment')->count());
    }

    public function test_a_caller_raises_a_ticket_from_the_create_page_with_only_a_reason(): void
    {
        $this->actingAs($this->callerUser);
        $category = ComplaintCategory::query()->where('name', 'Desktop / Laptop')->firstOrFail();
        $reason = $category->reasons()->where('name', 'Mouse not working')->firstOrFail();

        Livewire::test(CreateComplaint::class)
            ->fillForm([
                'category_id' => $category->id,
                'reason_id' => $reason->id,
                'priority_id' => $this->priority('High')->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $ticket = Complaint::query()->firstOrFail();
        $this->assertSame($this->callerUser->id, $ticket->raised_by);
        $this->assertSame('IT', $ticket->handler_role);
        $this->assertSame($reason->id, $ticket->reason_id);
        $this->assertSame('Mouse not working', $ticket->subject);
        $this->assertNull($ticket->description);
    }

    public function test_the_desktop_category_lists_the_workstation_reasons(): void
    {
        $reasons = ComplaintCategory::query()->where('name', 'Desktop / Laptop')->firstOrFail()->reasons()->pluck('name')->all();

        foreach (['Mouse not working', 'Keyboard not working', 'Monitor not working', 'CPU issue', 'Headphone issue', 'Voice issue', 'Dialer issue', 'Slow call flow', 'Internet not working'] as $reason) {
            $this->assertContains($reason, $reasons);
        }

        $this->assertSame('Other issue', end($reasons));

        $fynnOn = ComplaintCategory::query()->where('name', 'Fynn-On Application')->firstOrFail()->reasons()->pluck('name')->all();
        $this->assertContains('Customer Journey', $fynnOn);
        $this->assertSame('Other', end($fynnOn));
    }

    public function test_a_supervisor_raises_a_ticket_for_someone_in_their_team_who_then_owns_it_too(): void
    {
        $this->assertSame([$this->callerUser->id], $this->complaints->teamMemberOptionsFor($this->teamLeaderUser)->keys()->all());
        $this->assertEqualsCanonicalizing([$this->callerUser->id, $this->teamLeaderUser->id], $this->complaints->teamMemberOptionsFor($this->managerUser)->keys()->all());
        $this->assertTrue($this->complaints->teamMemberOptionsFor($this->callerUser)->isEmpty());
        $this->assertFalse($this->complaints->canRaiseForOthers($this->callerUser));
        $this->assertTrue($this->complaints->canRaiseForOthers($this->teamLeaderUser));

        // The Admin may raise for anybody at all.
        $this->assertTrue($this->complaints->canRaiseForOthers($this->adminUser));
        $this->assertTrue($this->complaints->teamMemberOptionsFor($this->adminUser)->has($this->itUser->id));
        $this->assertFalse($this->complaints->teamMemberOptionsFor($this->adminUser)->has($this->adminUser->id));

        $ticket = $this->raise($this->teamLeaderUser, 'IT', 'Medium', ['on_behalf_of' => $this->callerUser->id]);

        $this->assertSame($this->teamLeaderUser->id, $ticket->raised_by);
        $this->assertSame($this->callerUser->id, $ticket->on_behalf_of);
        $this->assertSame('Tarun Leader (for Ravi Caller)', $ticket->raisedByLabel());
        $this->assertStringContainsString('raised for you', $this->callerUser->notifications()->first()->data['title']);

        $this->assertVisible($this->callerUser, [$ticket]);
        $this->assertVisible($this->teamLeaderUser, [$ticket]);

        $this->complaints->resolve($ticket, $this->itUser, 'Done.');
        $this->complaints->close($ticket, $this->callerUser);
        $this->assertSame(ComplaintStatus::Closed, $ticket->status);
    }

    public function test_a_ticket_cannot_be_raised_for_somebody_outside_ones_own_team(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->raise($this->teamLeaderUser, 'IT', 'Medium', ['on_behalf_of' => $this->managerUser->id]);
    }

    public function test_the_create_page_offers_the_team_choice_to_a_supervisor_and_not_to_a_caller(): void
    {
        $this->actingAs($this->teamLeaderUser);
        Livewire::test(CreateComplaint::class)->assertOk()->assertSee('Raising this for')->assertSee('Someone in my team');

        $this->actingAs($this->callerUser);
        Livewire::test(CreateComplaint::class)->assertOk()->assertDontSee('Raising this for');
    }

    public function test_the_create_page_refuses_a_team_member_outside_the_supervisors_tree(): void
    {
        $this->actingAs($this->teamLeaderUser);
        $category = ComplaintCategory::query()->where('name', 'IT')->firstOrFail();

        Livewire::test(CreateComplaint::class)
            ->fillForm([
                'raised_for' => 'team',
                'on_behalf_of' => $this->managerUser->id,
                'category_id' => $category->id,
                'reason_id' => $category->reasons()->first()->id,
                'priority_id' => $this->priority('Medium')->id,
            ])
            ->call('create')
            ->assertHasFormErrors(['on_behalf_of']);

        $this->assertSame(0, Complaint::query()->count());
    }

    public function test_the_create_page_insists_on_a_supervisor_for_a_supervisor_routed_category(): void
    {
        $this->actingAs($this->callerUser);
        $category = ComplaintCategory::query()->where('name', 'Sales / Team')->firstOrFail();

        Livewire::test(CreateComplaint::class)
            ->fillForm([
                'category_id' => $category->id,
                'reason_id' => $category->reasons()->first()->id,
                'priority_id' => $this->priority('Medium')->id,
            ])
            ->call('create')
            ->assertHasFormErrors(['escalate_to']);
    }

    public function test_the_listing_and_ticket_page_render_with_the_lifecycle_actions(): void
    {
        $ticket = $this->raise($this->callerUser, 'IT', 'Medium');

        $this->actingAs($this->itUser);
        Livewire::test(ListComplaints::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$ticket])
            ->callAction(TestAction::make('takeUp')->table($ticket))
            ->assertNotified('Ticket taken up');

        $this->assertSame(ComplaintStatus::InProgress, $ticket->fresh()->status);

        Livewire::test(ViewComplaint::class, ['record' => $ticket->getRouteKey()])
            ->assertOk()
            ->assertSee($ticket->ticket_no)
            ->assertActionVisible('resolve')
            ->assertActionHidden('close')
            ->callAction('resolve', ['resolution_note' => 'Swapped the mouse.'])
            ->assertNotified('Ticket resolved');

        $this->assertSame(ComplaintStatus::Resolved, $ticket->fresh()->status);

        $this->actingAs($this->callerUser);
        Livewire::test(ViewComplaint::class, ['record' => $ticket->getRouteKey()])
            ->assertOk()
            ->assertActionVisible('close')
            ->assertActionVisible('reopen')
            ->assertActionHidden('resolve');
    }

    public function test_the_admin_adds_a_category_with_its_reasons_and_routing(): void
    {
        $this->actingAs($this->adminUser);

        Livewire::test(CreateComplaintCategory::class)
            ->fillForm([
                'name' => 'Vehicle',
                'description' => 'Company vehicles and fuel cards.',
                'routing' => 'team',
                'handler_roles' => ['Accounts'],
                'is_active' => true,
                'sort_order' => 9,
                'reasons' => [
                    ['name' => 'Fuel card blocked', 'is_active' => true],
                    ['name' => 'Service due', 'is_active' => true],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $category = ComplaintCategory::query()->where('name', 'Vehicle')->firstOrFail();
        $this->assertSame(['Accounts'], $category->handlerRoles());
        $this->assertSame(['Fuel card blocked', 'Service due'], $category->reasons()->pluck('name')->all());

        $ticket = $this->raise($this->callerUser, 'Vehicle', 'Low');
        $this->assertSame('Accounts', $ticket->handler_role);
    }

    public function test_changing_a_priority_sla_applies_to_tickets_raised_afterwards(): void
    {
        $before = $this->raise($this->callerUser, 'IT', 'Low');

        $this->priority('Low')->update(['resolve_within_minutes' => 120]);

        $after = $this->raise($this->callerUser, 'IT', 'Low');

        $this->assertTrue($before->due_at->eq(now()->addMinutes(2 * 1440)));
        $this->assertTrue($after->due_at->eq(now()->addMinutes(120)));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function raise(User $raiser, string $category, string $priority, array $extra = []): Complaint
    {
        $categoryModel = ComplaintCategory::query()->where('name', $category)->firstOrFail();

        return $this->complaints->raise($raiser, [
            'category_id' => $categoryModel->id,
            'reason_id' => $categoryModel->reasons()->value('id'),
            'priority_id' => $this->priority($priority)->id,
            'subject' => 'Something about '.$category,
            'description' => 'A description long enough to pass validation.',
            ...$extra,
        ]);
    }

    private function priority(string $name): ComplaintPriority
    {
        return ComplaintPriority::query()->where('name', $name)->firstOrFail();
    }

    /**
     * @param  list<Complaint>  $expected
     */
    private function assertVisible(User $user, array $expected): void
    {
        $this->actingAs($user);

        $visible = ComplaintResource::getEloquentQuery()->pluck('id')->sort()->values()->all();
        $expectedIds = collect($expected)->pluck('id')->sort()->values()->all();

        $this->assertSame($expectedIds, $visible, $user->name.' sees the wrong tickets.');
    }
}
