<?php

namespace Tests\Feature;

use App\Enums\NotificationCategory;
use App\Filament\Resources\Announcements\AnnouncementResource;
use App\Filament\Resources\Announcements\Pages\CreateAnnouncement;
use App\Filament\Resources\Announcements\Pages\EditAnnouncement;
use App\Filament\Resources\Announcements\Pages\ListAnnouncements;
use App\Filament\Resources\Announcements\RelationManagers\RecipientsRelationManager;
use App\Livewire\AnnouncementPrompt;
use App\Livewire\CategorizedDatabaseNotifications;
use App\Livewire\ReminderPopup;
use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Models\Employee;
use App\Models\User;
use App\Services\AnnouncementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Setting → Announcements: the Admin sends a message company wide, to chosen
 * roles or to chosen designations. Each recipient is blocked until they
 * acknowledge it, and it stays in their bell to read again.
 */
class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $caller;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Caller', 'Manager'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        $this->admin = $this->userWith('Admin', Employee::DESIGNATION_ADMIN);
        $this->caller = $this->userWith('Caller', Employee::DESIGNATION_CALLER);
        $this->manager = $this->userWith('Manager', Employee::DESIGNATION_MANAGER);
    }

    private function userWith(string $role, int $designation): User
    {
        $user = User::factory()->create(['employee_id' => Employee::factory()->create(['designation' => $designation])->id]);
        $user->assignRole($role);

        return $user;
    }

    private function publish(array $attributes = []): Announcement
    {
        $announcement = Announcement::factory()->create(['created_by' => $this->admin->id, ...$attributes]);
        app(AnnouncementService::class)->publish($announcement);

        return $announcement;
    }

    public function test_admin_sends_a_company_wide_announcement_to_every_active_user(): void
    {
        $switchedOff = User::factory()->create(['is_active' => false]);
        $exited = User::factory()->create(['employee_id' => Employee::factory()->create(['exit_status' => 'yes'])->id]);

        $this->actingAs($this->admin);

        Livewire::test(CreateAnnouncement::class)
            ->fillForm([
                'title' => 'Office closed Friday',
                'message' => 'The office stays closed this Friday for maintenance.',
                'level' => 'warning',
                'audience' => Announcement::AUDIENCE_COMPANY,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Announcement sent');

        $announcement = Announcement::sole();

        $this->assertSame($this->admin->id, $announcement->created_by);
        $this->assertSame(3, $announcement->recipients_count);
        $this->assertEqualsCanonicalizing(
            [$this->admin->id, $this->caller->id, $this->manager->id],
            $announcement->recipients()->pluck('user_id')->all(),
        );

        $notification = $this->caller->notifications()->sole();
        $this->assertSame(NotificationCategory::Announcement->value, $notification->category);
        $this->assertSame('The office stays closed this Friday for maintenance.', $notification->data['body']);

        $this->assertSame(0, $switchedOff->notifications()->count());
        $this->assertSame(0, $exited->notifications()->count());
    }

    public function test_role_wise_announcement_reaches_only_those_roles(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateAnnouncement::class)
            ->fillForm([
                'title' => 'Callers only',
                'message' => 'New calling script from Monday.',
                'level' => 'info',
                'audience' => Announcement::AUDIENCE_ROLES,
                'audience_roles' => ['Caller'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $announcement = Announcement::sole();

        $this->assertSame(['Caller'], $announcement->audience_roles);
        $this->assertSame([$this->caller->id], $announcement->recipients()->pluck('user_id')->all());
        $this->assertSame(0, $this->manager->notifications()->count());
    }

    public function test_designation_wise_announcement_reaches_only_those_designations(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateAnnouncement::class)
            ->fillForm([
                'title' => 'Managers only',
                'message' => 'Review meeting at 4 PM.',
                'level' => 'info',
                'audience' => Announcement::AUDIENCE_DESIGNATIONS,
                'audience_designations' => [Employee::DESIGNATION_MANAGER],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame([$this->manager->id], Announcement::sole()->recipients()->pluck('user_id')->all());
        $this->assertSame(0, $this->caller->notifications()->count());
    }

    public function test_roles_or_designations_are_required_for_their_audience(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateAnnouncement::class)
            ->fillForm(['title' => 'x', 'message' => 'y', 'level' => 'info', 'audience' => Announcement::AUDIENCE_ROLES])
            ->call('create')
            ->assertHasFormErrors(['audience_roles' => 'required']);

        Livewire::test(CreateAnnouncement::class)
            ->fillForm(['title' => 'x', 'message' => 'y', 'level' => 'info', 'audience' => Announcement::AUDIENCE_DESIGNATIONS])
            ->call('create')
            ->assertHasFormErrors(['audience_designations' => 'required']);

        $this->assertSame(0, Announcement::count());
    }

    public function test_title_and_message_are_required(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateAnnouncement::class)
            ->fillForm(['title' => null, 'message' => null])
            ->call('create')
            ->assertHasFormErrors(['title' => 'required', 'message' => 'required']);

        $this->assertSame(0, Announcement::count());
    }

    public function test_stop_asking_time_is_saved_without_seconds_and_must_be_in_the_future(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateAnnouncement::class)
            ->fillForm(['title' => 'Late', 'message' => 'Over.', 'level' => 'info', 'expires_at' => now()->subHour()->format('Y-m-d H:i')])
            ->call('create')
            ->assertHasFormErrors(['expires_at' => 'after']);

        $expiresAt = now()->addDays(2)->setTime(12, 15);

        Livewire::test(CreateAnnouncement::class)
            ->fillForm(['title' => 'Handbook', 'message' => 'Read it.', 'level' => 'info', 'expires_at' => $expiresAt->format('Y-m-d H:i')])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(Announcement::sole()->expires_at->equalTo($expiresAt));
    }

    public function test_the_sender_is_not_blocked_by_their_own_announcement(): void
    {
        $this->publish(['title' => 'From the admin']);

        $this->actingAs($this->admin);

        Livewire::test(AnnouncementPrompt::class)->assertDontSee('From the admin');
    }

    public function test_prompt_blocks_until_each_announcement_is_acknowledged(): void
    {
        $first = $this->publish(['title' => 'Town hall at 5 PM']);
        $this->publish(['title' => 'New leave policy']);

        $this->actingAs($this->caller);

        $prompt = Livewire::test(AnnouncementPrompt::class)
            ->assertSee('Town hall at 5 PM')
            ->assertSee('1 of 2')
            ->assertDontSee('New leave policy');

        $prompt->call('acknowledgeAnnouncement', AnnouncementRecipient::where('user_id', $this->caller->id)->where('announcement_id', $first->id)->value('id'))
            ->assertDispatched('databaseNotificationsSent')
            ->assertDontSee('Town hall at 5 PM')
            ->assertSee('New leave policy');

        $this->assertNotNull(AnnouncementRecipient::where('user_id', $this->caller->id)->where('announcement_id', $first->id)->value('acknowledged_at'));
        $this->assertSame(1, $this->caller->unreadNotifications()->count());
        $this->assertSame(2, $this->caller->notifications()->count());
    }

    public function test_marking_the_bell_read_does_not_skip_the_acknowledgement(): void
    {
        $this->publish(['title' => 'Must still acknowledge']);

        $this->actingAs($this->caller);

        Livewire::test(CategorizedDatabaseNotifications::class)->call('markAllNotificationsAsRead');

        Livewire::test(AnnouncementPrompt::class)->assertSee('Must still acknowledge');
    }

    public function test_switched_off_or_expired_announcements_stop_blocking_but_stay_in_the_bell(): void
    {
        $this->publish(['title' => 'Switched off one', 'is_active' => false]);
        $this->publish(['title' => 'Expired one', 'expires_at' => now()->subHour()]);

        $this->actingAs($this->caller);

        Livewire::test(AnnouncementPrompt::class)
            ->assertDontSee('Switched off one')
            ->assertDontSee('Expired one');

        $this->assertSame(2, Livewire::test(CategorizedDatabaseNotifications::class)->instance()->getUnreadNotificationsCount());
    }

    public function test_a_user_cannot_acknowledge_someone_elses_announcement(): void
    {
        $this->publish();

        $this->actingAs($this->caller);

        Livewire::test(AnnouncementPrompt::class)
            ->call('acknowledgeAnnouncement', AnnouncementRecipient::where('user_id', $this->manager->id)->value('id'));

        $this->assertNull(AnnouncementRecipient::where('user_id', $this->manager->id)->value('acknowledged_at'));
    }

    public function test_announcement_reaches_the_bell_without_a_queue_worker(): void
    {
        Queue::fake();

        $this->publish(['title' => 'No worker needed']);

        Queue::assertNothingPushed();
        $this->assertSame('No worker needed', $this->caller->unreadNotifications()->sole()->data['title']);
    }

    public function test_announcements_do_not_open_the_reminder_pop_up(): void
    {
        $this->publish(['title' => 'Holiday list is out']);

        $this->actingAs($this->caller);

        Livewire::test(ReminderPopup::class)->assertDontSee('Holiday list is out');
    }

    public function test_listing_shows_audience_and_acknowledgement_progress(): void
    {
        $announcement = $this->publish(['audience' => Announcement::AUDIENCE_ROLES, 'audience_roles' => ['Caller', 'Manager']]);
        app(AnnouncementService::class)->acknowledge(AnnouncementRecipient::where('user_id', $this->caller->id)->sole());

        $this->actingAs($this->admin);

        Livewire::test(ListAnnouncements::class)
            ->assertCanSeeTableRecords([$announcement])
            ->assertSee('Roles: Caller, Manager')
            ->assertSee('1 / 2');

        Livewire::test(RecipientsRelationManager::class, ['ownerRecord' => $announcement, 'pageClass' => EditAnnouncement::class])
            ->assertCanSeeTableRecords($announcement->recipients)
            ->filterTable('acknowledged_at', false)
            ->assertCanSeeTableRecords($announcement->recipients()->where('user_id', $this->manager->id)->get())
            ->assertCanNotSeeTableRecords($announcement->recipients()->where('user_id', $this->caller->id)->get());
    }

    public function test_only_admin_can_open_announcements_and_it_sits_in_the_setting_menu(): void
    {
        $url = AnnouncementResource::getUrl('index');

        $this->actingAs($this->admin)
            ->followingRedirects()
            ->get('/admin')
            ->assertOk()
            ->assertSee($url, escape: false);

        // A caller would first be bounced by the monthly-target gate.
        $this->actingAs(User::factory()->create(['employee_id' => Employee::factory()->create()->id]))
            ->get($url)
            ->assertForbidden();
    }

    public function test_topbar_bell_renders_with_the_unread_count_badge(): void
    {
        $this->publish();

        $this->actingAs($this->caller);

        Livewire::test(CategorizedDatabaseNotifications::class)
            ->assertSeeHtml('fi-topbar-database-notifications-btn')
            ->assertSeeHtml('fi-icon-btn-badge-ctn');
    }
}
