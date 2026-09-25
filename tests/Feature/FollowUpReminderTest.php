<?php

namespace Tests\Feature;

use App\Enums\NotificationCategory;
use App\Filament\Resources\FollowUps\Pages\ListFollowUps;
use App\Livewire\CategorizedDatabaseNotifications;
use App\Livewire\ReminderPopup;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use App\Services\FollowUpReminderService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FollowUpReminderTest extends TestCase
{
    use RefreshDatabase;

    private Employee $caller;

    private User $callerUser;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Caller'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->caller = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER]);
        $this->callerUser = User::factory()->create(['employee_id' => $this->caller->id]);
        $this->callerUser->assignRole('Caller');
    }

    /*
    |--------------------------------------------------------------------------
    | Sending reminders
    |--------------------------------------------------------------------------
    */

    public function test_a_follow_up_coming_due_notifies_the_customer_owner_once(): void
    {
        $followUp = $this->followUp(['next_follow_up_date' => now()->addMinutes(5)]);

        $this->assertSame(1, app(FollowUpReminderService::class)->sendDueReminders());
        $this->assertSame(0, app(FollowUpReminderService::class)->sendDueReminders());

        $notification = $this->callerUser->notifications()->sole();
        $this->assertSame(NotificationCategory::FollowUp->value, $notification->category);
        $this->assertSame($followUp->id, data_get($notification->data, 'viewData.follow_up_id'));
        $this->assertStringContainsString('Follow-up due', $notification->data['title']);
        $this->assertNotNull($followUp->fresh()->reminded_at);
    }

    public function test_follow_ups_outside_the_reminder_window_are_not_reminded(): void
    {
        $this->followUp(['next_follow_up_date' => now()->addHours(3)]);
        $this->followUp(['next_follow_up_date' => now()->subDays(3)]);
        $this->followUp(['next_follow_up_date' => null]);

        $this->assertSame(0, app(FollowUpReminderService::class)->sendDueReminders());
    }

    public function test_only_the_current_follow_up_of_a_prospect_is_reminded(): void
    {
        $customer = $this->customer();
        $this->followUp(['customer_id' => $customer->id, 'next_follow_up_date' => now()->addMinutes(2)]);
        $this->followUp(['customer_id' => $customer->id, 'next_follow_up_date' => now()->addDays(2)]);

        $this->assertSame(0, app(FollowUpReminderService::class)->sendDueReminders());
    }

    public function test_a_raw_lead_reminds_its_lead_owner(): void
    {
        $lead = $this->lead(['next_follow_up_date' => now()->addMinutes(5)]);

        app(FollowUpReminderService::class)->sendDueReminders();

        $this->assertSame(1, $this->callerUser->notifications()->count());
        $this->assertSame($lead->followUps()->sole()->id, data_get($this->callerUser->notifications()->sole()->data, 'viewData.follow_up_id'));
    }

    public function test_the_command_sends_reminders(): void
    {
        $this->followUp(['next_follow_up_date' => now()->addMinutes(5)]);

        $this->artisan('follow-ups:send-reminders')->assertSuccessful();

        $this->assertSame(1, $this->callerUser->notifications()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Pop-up
    |--------------------------------------------------------------------------
    */

    public function test_the_pop_up_shows_the_pending_reminder(): void
    {
        $this->remindedFollowUp();

        $this->actingAs($this->callerUser);

        Livewire::test(ReminderPopup::class)
            ->assertSee('Follow-up due')
            ->assertSee('Drop')
            ->assertSee('Reschedule')
            ->assertSee('Skip');
    }

    public function test_closing_needs_remarks_and_logs_the_follow_up_as_done(): void
    {
        $followUp = $this->remindedFollowUp();
        $notification = $this->callerUser->notifications()->sole();

        $this->actingAs($this->callerUser);

        Livewire::test(ReminderPopup::class)
            ->call('openMode', 'close')
            ->call('closeReminder', $notification->id)
            ->assertHasErrors(['remarks' => 'required'])
            ->set('remarks', 'Spoke to the customer, documents on the way.')
            ->call('closeReminder', $notification->id)
            ->assertHasNoErrors()
            ->assertDontSee('Follow-up due');

        $latest = FollowUp::query()->forSameSubjectAs($followUp)->latest('id')->first();
        $this->assertNotSame($followUp->id, $latest->id);
        $this->assertNull($latest->next_follow_up_date);
        $this->assertSame('Spoke to the customer, documents on the way.', $latest->remarks);

        $notification->refresh();
        $this->assertNotNull($notification->read_at);
        $this->assertSame('closed', $notification->resolution);
    }

    public function test_rescheduling_moves_the_follow_up_in_its_own_log(): void
    {
        $followUp = $this->remindedFollowUp();
        $notification = $this->callerUser->notifications()->sole();
        $when = now()->addDays(2)->setTime(11, 30);

        $this->actingAs($this->callerUser);

        Livewire::test(ReminderPopup::class)
            ->set('rescheduleAt', now()->subHour()->format('Y-m-d\TH:i'))
            ->call('rescheduleReminder', $notification->id)
            ->assertHasErrors(['rescheduleAt'])
            ->set('rescheduleAt', $when->format('Y-m-d\TH:i'))
            ->call('rescheduleReminder', $notification->id)
            ->assertHasNoErrors();

        $latest = FollowUp::query()->forSameSubjectAs($followUp)->latest('id')->first();
        $this->assertTrue($latest->next_follow_up_date->equalTo($when));
        $this->assertNull($latest->reminded_at);
        $this->assertSame('rescheduled', $notification->fresh()->resolution);
    }

    public function test_dropping_logs_a_dropped_follow_up_with_no_next_date(): void
    {
        $followUp = $this->remindedFollowUp();
        $notification = $this->callerUser->notifications()->sole();

        $this->actingAs($this->callerUser);

        Livewire::test(ReminderPopup::class)
            ->set('remarks', 'Customer not reachable for a month.')
            ->call('dropFollowUp', $notification->id)
            ->assertHasNoErrors();

        $latest = FollowUp::query()->forSameSubjectAs($followUp)->latest('id')->first();
        $this->assertSame(FollowUpReminderService::STATUS_DROPPED, $latest->status);
        $this->assertNull($latest->next_follow_up_date);
        $this->assertSame(0, FollowUp::query()->latestPerSubject()->scheduled()->forSameSubjectAs($followUp)->count());
    }

    public function test_dropping_a_lead_follow_up_updates_the_lead(): void
    {
        $lead = $this->lead(['next_follow_up_date' => now()->addMinutes(5)]);
        app(FollowUpReminderService::class)->sendDueReminders();
        $notification = $this->callerUser->notifications()->sole();

        $this->actingAs($this->callerUser);

        Livewire::test(ReminderPopup::class)
            ->set('remarks', 'Not interested any more.')
            ->call('dropFollowUp', $notification->id);

        $lead->refresh();
        $this->assertSame(FollowUpReminderService::STATUS_DROPPED, $lead->status);
        $this->assertNull($lead->next_follow_up_date);
        $this->assertSame(2, $lead->followUps()->count());
    }

    public function test_skipping_marks_the_reminder_read(): void
    {
        $this->remindedFollowUp();
        $notification = $this->callerUser->notifications()->sole();

        $this->actingAs($this->callerUser);

        Livewire::test(ReminderPopup::class)
            ->call('skipReminder', $notification->id)
            ->assertDontSee('Follow-up due');

        $this->assertSame('skipped', $notification->fresh()->resolution);
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_any_notification_can_be_rescheduled_to_pop_up_later(): void
    {
        Notification::make()
            ->title('Eligibility: Ravi')
            ->body('Status changed')
            ->sendToDatabase($this->callerUser);

        $notification = $this->callerUser->notifications()->sole();
        $this->assertSame(NotificationCategory::Eligibility->value, $notification->category);

        $this->actingAs($this->callerUser);

        Livewire::test(ReminderPopup::class)
            ->assertSee('Eligibility: Ravi')
            ->assertDontSee('Drop follow-up')
            ->set('rescheduleAt', now()->addHours(2)->format('Y-m-d\TH:i'))
            ->call('rescheduleReminder', $notification->id)
            ->assertDontSee('Eligibility: Ravi');

        $notification->refresh();
        $this->assertNull($notification->read_at);
        $this->assertNotNull($notification->remind_at);

        $this->travel(3)->hours();

        Livewire::test(ReminderPopup::class)->assertSee('Eligibility: Ravi');
    }

    public function test_a_user_cannot_answer_someone_elses_reminder(): void
    {
        $this->remindedFollowUp();
        $notification = $this->callerUser->notifications()->sole();

        $intruder = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
        $this->actingAs($intruder);

        Livewire::test(ReminderPopup::class)->call('skipReminder', $notification->id);

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_drop_all_overdue_clears_the_backlog(): void
    {
        $this->followUp(['next_follow_up_date' => now()->subDays(3)]);
        $this->followUp(['next_follow_up_date' => now()->subDays(10)]);
        $this->followUp(['next_follow_up_date' => now()->addDay()]);

        $this->actingAs($this->callerUser);

        Livewire::test(ReminderPopup::class)->call('dropOverdueFollowUps');

        $this->assertSame(1, FollowUp::query()->latestPerSubject()->scheduled()->count());
        $this->assertSame(2, FollowUp::query()->where('status', FollowUpReminderService::STATUS_DROPPED)->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Bell and listings
    |--------------------------------------------------------------------------
    */

    public function test_the_bell_splits_notifications_into_category_tabs(): void
    {
        $this->remindedFollowUp();
        Notification::make()->title('Duplicate PAN Request Approved')->sendToDatabase($this->callerUser);

        $this->actingAs($this->callerUser);

        $bell = Livewire::test(CategorizedDatabaseNotifications::class);

        $this->assertSame(2, $bell->instance()->getUnreadNotificationsCount());
        $this->assertSame([
            NotificationCategory::FollowUp->value => 1,
            NotificationCategory::PanRequest->value => 1,
        ], collect($bell->instance()->getUnreadCountsByCategory())->sortKeys()->all());

        $bell->call('showCategory', NotificationCategory::PanRequest->value);

        $this->assertSame(1, $bell->instance()->getNotificationsQuery()->count());
        $this->assertSame(2, $bell->instance()->getUnreadNotificationsCount());

        $bell->call('markAllNotificationsAsRead');

        $this->assertSame(1, $this->callerUser->unreadNotifications()->count());
        $this->assertSame(NotificationCategory::FollowUp->value, $this->callerUser->unreadNotifications()->sole()->category);
    }

    public function test_follow_ups_can_be_dropped_in_bulk_from_the_listing(): void
    {
        $admin = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
        $admin->assignRole('Admin');

        $first = $this->followUp(['employee_id' => $this->caller->id, 'next_follow_up_date' => now()->subDay()]);
        $second = $this->followUp(['employee_id' => $this->caller->id, 'next_follow_up_date' => now()->subDays(2)]);

        $this->actingAs($admin);

        Livewire::test(ListFollowUps::class)
            ->selectTableRecords([$first->id, $second->id])
            ->callAction(TestAction::make('dropFollowUps')->table()->bulk(), ['remarks' => 'Old backlog'])
            ->assertHasNoActionErrors();

        $this->assertSame(0, FollowUp::query()->latestPerSubject()->scheduled()->count());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function followUp(array $attributes = []): FollowUp
    {
        return FollowUp::factory()->create([
            'customer_id' => $attributes['customer_id'] ?? $this->customer()->id,
            ...$attributes,
        ]);
    }

    private function remindedFollowUp(): FollowUp
    {
        $followUp = $this->followUp(['next_follow_up_date' => now()->addMinutes(5)]);

        app(FollowUpReminderService::class)->sendDueReminders();

        return $followUp;
    }

    private function customer(): Customer
    {
        return Customer::factory()->create([
            'assign_to' => $this->caller->id,
            'employee_id' => $this->caller->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function lead(array $attributes = []): Lead
    {
        return Lead::create([
            'employee_id' => $this->caller->id,
            'customer_name' => 'Ravi Kumar',
            'mobile_no' => '9876543210',
            'follow_up_type' => 'Call',
            'status' => 'Call Back',
            'remarks' => 'Asked to call back',
            ...$attributes,
        ]);
    }
}
