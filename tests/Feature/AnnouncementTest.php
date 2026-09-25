<?php

namespace Tests\Feature;

use App\Enums\NotificationCategory;
use App\Filament\Resources\Announcements\AnnouncementResource;
use App\Filament\Resources\Announcements\Pages\CreateAnnouncement;
use App\Livewire\AnnouncementBanner;
use App\Livewire\CategorizedDatabaseNotifications;
use App\Livewire\ReminderPopup;
use App\Models\Announcement;
use App\Models\Employee;
use App\Models\User;
use App\Services\AnnouncementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Setting → Announcements: the Admin flashes a message to every active
 * user's bell, and it floats on screen until each user dismisses it.
 */
class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $caller;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);

        $this->admin = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
        $this->admin->assignRole('Admin');

        $this->caller = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    }

    public function test_admin_creates_an_announcement_and_every_active_user_gets_it(): void
    {
        $switchedOff = User::factory()->create(['is_active' => false]);
        $exited = User::factory()->create(['employee_id' => Employee::factory()->create(['exit_status' => 'yes'])->id]);

        $this->actingAs($this->admin);

        Livewire::test(CreateAnnouncement::class)
            ->fillForm([
                'title' => 'Office closed Friday',
                'message' => 'The office stays closed this Friday for maintenance.',
                'level' => 'warning',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Announcement sent');

        $announcement = Announcement::sole();

        $this->assertSame($this->admin->id, $announcement->created_by);
        $this->assertSame(2, $announcement->recipients_count);

        $notification = $this->caller->notifications()->sole();
        $this->assertSame(NotificationCategory::Announcement->value, $notification->category);
        $this->assertSame('Office closed Friday', $notification->data['title']);
        $this->assertSame($announcement->id, data_get($notification->data, 'viewData.announcement_id'));

        $this->assertSame(1, $this->admin->notifications()->count());
        $this->assertSame(0, $switchedOff->notifications()->count());
        $this->assertSame(0, $exited->notifications()->count());
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

    public function test_only_admin_can_open_announcements_and_it_sits_in_the_setting_menu(): void
    {
        $url = AnnouncementResource::getUrl('index');

        $this->actingAs($this->admin)
            ->followingRedirects()
            ->get('/admin')
            ->assertOk()
            ->assertSee($url, escape: false);

        $this->actingAs($this->caller)->get($url)->assertForbidden();
    }

    public function test_banner_floats_unread_announcements_until_dismissed(): void
    {
        $announcement = Announcement::factory()->create(['title' => 'Town hall at 5 PM']);
        app(AnnouncementService::class)->publish($announcement);

        $this->actingAs($this->caller);

        $banner = Livewire::test(AnnouncementBanner::class)->assertSee('Town hall at 5 PM');

        $banner->call('dismissAnnouncement', $this->caller->notifications()->sole()->getKey())
            ->assertDispatched('databaseNotificationsSent')
            ->assertDontSee('Town hall at 5 PM');

        $this->assertSame(0, $this->caller->unreadNotifications()->count());
    }

    public function test_switched_off_or_expired_announcements_stop_floating_but_stay_in_the_bell(): void
    {
        $service = app(AnnouncementService::class);
        $service->publish(Announcement::factory()->inactive()->create(['title' => 'Switched off one']));
        $service->publish(Announcement::factory()->expired()->create(['title' => 'Expired one']));

        $this->actingAs($this->caller);

        Livewire::test(AnnouncementBanner::class)
            ->assertDontSee('Switched off one')
            ->assertDontSee('Expired one');

        $this->assertSame(2, Livewire::test(CategorizedDatabaseNotifications::class)->instance()->getUnreadNotificationsCount());
    }

    public function test_a_user_cannot_dismiss_someone_elses_announcement(): void
    {
        app(AnnouncementService::class)->publish(Announcement::factory()->create());

        $this->actingAs($this->caller);

        Livewire::test(AnnouncementBanner::class)
            ->call('dismissAnnouncement', $this->admin->notifications()->sole()->getKey());

        $this->assertSame(1, $this->admin->unreadNotifications()->count());
    }

    public function test_announcements_do_not_open_the_reminder_pop_up(): void
    {
        app(AnnouncementService::class)->publish(Announcement::factory()->create(['title' => 'Holiday list is out']));

        $this->actingAs($this->caller);

        Livewire::test(ReminderPopup::class)->assertDontSee('Holiday list is out');
    }

    public function test_topbar_bell_renders_with_the_unread_count_badge(): void
    {
        $service = app(AnnouncementService::class);
        $service->publish(Announcement::factory()->create());
        $service->publish(Announcement::factory()->create());

        $this->actingAs($this->caller);

        Livewire::test(CategorizedDatabaseNotifications::class)
            ->assertSeeHtml('fi-topbar-database-notifications-btn')
            ->assertSeeHtml('fi-icon-btn-badge-ctn');
    }
}
