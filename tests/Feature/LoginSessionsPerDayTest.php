<?php

namespace Tests\Feature;

use App\Filament\Resources\UserLoginSessions\Pages\ListUserLoginSessions;
use App\Models\Employee;
use App\Models\User;
use App\Models\UserLoginSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The login log reports how many times each person signed in on the day
 * a row belongs to — the repeated-login pattern a supervisor is actually
 * looking for is invisible when every row just says "logged in".
 */
class LoginSessionsPerDayTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Employee $employee;

    private User $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::findOrCreate('Admin'));

        $this->employee = Employee::factory()->create(['emp_name' => 'Repeat Logger']);
        $this->subject = User::factory()->create(['employee_id' => $this->employee->id]);
    }

    private function loginSession(string $loginAt): UserLoginSession
    {
        return UserLoginSession::create([
            'user_id' => $this->subject->id,
            'employee_id' => $this->employee->id,
            'session_id' => 'sess-'.$loginAt,
            'login_at' => $loginAt,
            'logout_at' => null,
            'last_seen_at' => $loginAt,
            'last_activity_at' => $loginAt,
            'screen_time_seconds' => 60,
        ]);
    }

    public function test_each_row_reports_that_days_login_count(): void
    {
        $today = now()->startOfMonth()->addDays(4);

        $first = $this->loginSession($today->copy()->setTime(9, 15)->toDateTimeString());
        $second = $this->loginSession($today->copy()->setTime(13, 40)->toDateTimeString());
        $third = $this->loginSession($today->copy()->setTime(17, 5)->toDateTimeString());

        // A different day for the same person must not be added in.
        $otherDay = $this->loginSession($today->copy()->addDay()->setTime(10, 0)->toDateTimeString());

        $this->actingAs($this->admin);

        Livewire::test(ListUserLoginSessions::class)
            ->assertTableColumnStateSet('logins_on_day', 3, $first)
            ->assertTableColumnStateSet('logins_on_day', 3, $second)
            ->assertTableColumnStateSet('logins_on_day', 3, $third)
            ->assertTableColumnStateSet('logins_on_day', 1, $otherDay);
    }

    public function test_another_users_logins_are_not_counted_into_the_total(): void
    {
        $day = now()->startOfMonth()->addDays(4);

        $mine = $this->loginSession($day->copy()->setTime(9, 0)->toDateTimeString());

        $otherEmployee = Employee::factory()->create();
        $otherUser = User::factory()->create(['employee_id' => $otherEmployee->id]);

        foreach ([8, 11, 15] as $hour) {
            UserLoginSession::create([
                'user_id' => $otherUser->id,
                'employee_id' => $otherEmployee->id,
                'session_id' => 'other-'.$hour,
                'login_at' => $day->copy()->setTime($hour, 0)->toDateTimeString(),
                'last_seen_at' => $day->copy()->setTime($hour, 0)->toDateTimeString(),
                'last_activity_at' => $day->copy()->setTime($hour, 0)->toDateTimeString(),
                'screen_time_seconds' => 60,
            ]);
        }

        $this->actingAs($this->admin);

        Livewire::test(ListUserLoginSessions::class)
            ->assertTableColumnStateSet('logins_on_day', 1, $mine);
    }

    public function test_the_repeat_login_filter_shows_only_days_with_more_than_one_login(): void
    {
        $day = now()->startOfMonth()->addDays(4);

        $repeatedA = $this->loginSession($day->copy()->setTime(9, 0)->toDateTimeString());
        $repeatedB = $this->loginSession($day->copy()->setTime(14, 0)->toDateTimeString());
        $single = $this->loginSession($day->copy()->addDay()->setTime(9, 0)->toDateTimeString());

        $this->actingAs($this->admin);

        Livewire::test(ListUserLoginSessions::class)
            ->filterTable('multiple_logins')
            ->assertCanSeeTableRecords([$repeatedA, $repeatedB])
            ->assertCanNotSeeTableRecords([$single]);
    }

    /**
     * The Activity column used to order by `is_active`, which is a
     * derived accessor and not a column — clicking the header threw an
     * "Unknown column" error.
     */
    public function test_the_activity_column_can_be_sorted(): void
    {
        $day = now()->startOfMonth()->addDays(4);

        $this->loginSession($day->copy()->setTime(9, 0)->toDateTimeString());
        $this->loginSession($day->copy()->setTime(10, 0)->toDateTimeString());

        $this->actingAs($this->admin);

        Livewire::test(ListUserLoginSessions::class)
            ->sortTable('activity_status')
            ->assertOk()
            ->sortTable('activity_status', 'desc')
            ->assertOk();
    }

    public function test_the_logins_per_day_column_can_be_sorted(): void
    {
        $day = now()->startOfMonth()->addDays(4);

        $this->loginSession($day->copy()->setTime(9, 0)->toDateTimeString());
        $this->loginSession($day->copy()->setTime(10, 0)->toDateTimeString());

        $this->actingAs($this->admin);

        Livewire::test(ListUserLoginSessions::class)
            ->sortTable('logins_on_day')
            ->assertOk();
    }
}
