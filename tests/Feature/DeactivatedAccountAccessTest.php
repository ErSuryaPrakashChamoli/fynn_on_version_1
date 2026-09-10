<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Models\Employee;
use App\Models\User;
use App\Models\UserLoginSession;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * An exit only ever set employees.exit_status. It left the user row
 * untouched, so somebody who had left the company kept a working login
 * and full sight of their own leads and customers — their records go on
 * being visible to the hierarchy above them
 * (ExitedEmployeeRecordVisibilityTest), the person does not.
 *
 * Two doors are shut here: the login form, and an already-open session.
 */
class DeactivatedAccountAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Admin');

        Filament::setCurrentPanel('admin');
    }

    private function userForEmployee(string $exitStatus): User
    {
        $employee = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'exit_status' => $exitStatus,
        ]);

        return User::factory()->create([
            'employee_id' => $employee->id,
            'password' => bcrypt('password'),
        ]);
    }

    private function attemptLogin(User $user): Testable
    {
        return Livewire::test(Login::class)
            ->fillForm([
                'email' => $user->email,
                'password' => 'password',
            ])
            ->call('authenticate');
    }

    public function test_an_exited_employee_cannot_log_in(): void
    {
        $user = $this->userForEmployee('yes');

        $this->attemptLogin($user)->assertHasFormErrors(['email']);

        $this->assertGuest();
    }

    public function test_a_deactivated_user_cannot_log_in(): void
    {
        $user = $this->userForEmployee('no');
        $user->forceFill(['is_active' => false])->save();

        $this->attemptLogin($user)->assertHasFormErrors(['email']);

        $this->assertGuest();
    }

    public function test_an_employee_still_on_the_rolls_logs_in_as_before(): void
    {
        $user = $this->userForEmployee('no');

        $this->attemptLogin($user)->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_an_open_session_ends_the_moment_the_employee_is_marked_as_exited(): void
    {
        $user = $this->userForEmployee('no');

        $session = UserLoginSession::create([
            'user_id' => $user->id,
            'employee_id' => $user->employee_id,
            'session_id' => 'test-session',
            'login_at' => now()->subMinutes(5),
            'last_seen_at' => now(),
            'last_activity_at' => now(),
            'screen_time_seconds' => 300,
        ]);

        $user->employee->forceFill([
            'exit_status' => 'yes',
            'exit_date' => today()->toDateString(),
        ])->save();

        $this->actingAs($user)
            ->withSession(['login_session_id' => $session->id])
            ->get('/admin')
            ->assertRedirect('/admin/login');

        $this->assertGuest();

        $session->refresh();

        $this->assertNotNull($session->logout_at);
        $this->assertSame(
            UserLoginSession::REASON_ACCOUNT_DEACTIVATED,
            $session->logout_reason
        );
    }

    public function test_an_active_employee_keeps_working(): void
    {
        $user = $this->userForEmployee('no');

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    /**
     * A user with no employee row at all (an Admin seat, an integration
     * account) is judged on users.is_active alone and is not swept up by
     * the exit check.
     */
    public function test_a_user_without_an_employee_row_is_unaffected(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');

        $this->assertFalse($user->isDeactivated());

        $this->actingAs($user)->get('/admin')->assertOk();
    }
}
