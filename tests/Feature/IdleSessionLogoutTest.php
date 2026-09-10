<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserLoginSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Idle logout: fifteen minutes with nobody at the keyboard ends the
 * session.
 *
 * The browser countdown is only UX — these tests exercise the server
 * side, which is what actually refuses a stale session.
 */
class IdleSessionLogoutTest extends TestCase
{
    use RefreshDatabase;

    private function signedInUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('Admin'));

        return $user;
    }

    private function openSessionFor(User $user, ?\DateTimeInterface $lastActivity = null): UserLoginSession
    {
        return UserLoginSession::create([
            'user_id' => $user->id,
            'employee_id' => $user->employee_id,
            'session_id' => 'test-session',
            'login_at' => now()->subHour(),
            'last_seen_at' => now(),
            'last_activity_at' => $lastActivity ?? now(),
            'screen_time_seconds' => 600,
        ]);
    }

    public function test_an_idle_session_is_signed_out_and_recorded_as_a_timeout(): void
    {
        $user = $this->signedInUser();
        $session = $this->openSessionFor($user, now()->subMinutes(16));

        $response = $this->actingAs($user)
            ->withSession(['login_session_id' => $session->id])
            ->get('/admin');

        $response->assertRedirect('/admin/login');
        $this->assertGuest();

        $session->refresh();

        $this->assertNotNull($session->logout_at);
        $this->assertSame(UserLoginSession::REASON_SESSION_TIMEOUT, $session->logout_reason);
    }

    public function test_a_session_just_inside_the_window_is_left_alone(): void
    {
        $user = $this->signedInUser();
        $session = $this->openSessionFor($user, now()->subMinutes(14));

        $this->actingAs($user)
            ->withSession(['login_session_id' => $session->id])
            ->get('/admin')
            ->assertOk();

        $this->assertAuthenticated();
        $this->assertNull($session->refresh()->logout_at);
    }

    /**
     * Loading a panel page is itself an interaction, so an actively-used
     * LMS stays signed in even if the heartbeat request never lands.
     */
    public function test_a_panel_request_refreshes_the_idle_clock(): void
    {
        $user = $this->signedInUser();
        $session = $this->openSessionFor($user, now()->subMinutes(10));

        $this->actingAs($user)
            ->withSession(['login_session_id' => $session->id])
            ->get('/admin')
            ->assertOk();

        $this->assertTrue(
            $session->refresh()->last_activity_at->greaterThan(now()->subMinute()),
            'A real page request should have advanced last_activity_at.',
        );
    }

    public function test_idle_logout_can_be_switched_off_entirely(): void
    {
        config(['session.idle_timeout' => 0]);

        $user = $this->signedInUser();
        $session = $this->openSessionFor($user, now()->subDay());

        $this->actingAs($user)
            ->withSession(['login_session_id' => $session->id])
            ->get('/admin')
            ->assertOk();

        $this->assertAuthenticated();
        $this->assertNull($session->refresh()->logout_at);
    }

    public function test_a_session_with_no_recorded_interaction_falls_back_to_its_login_time(): void
    {
        $user = $this->signedInUser();

        $session = UserLoginSession::create([
            'user_id' => $user->id,
            'session_id' => 'legacy-row',
            'login_at' => now()->subHours(3),
            'last_seen_at' => now()->subHours(3),
            'last_activity_at' => null,
            'screen_time_seconds' => 0,
        ]);

        $this->assertTrue($session->isIdle());

        $this->actingAs($user)
            ->withSession(['login_session_id' => $session->id])
            ->get('/admin')
            ->assertRedirect('/admin/login');

        $this->assertSame(
            UserLoginSession::REASON_SESSION_TIMEOUT,
            $session->refresh()->logout_reason,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Sessions closed behind the browser's back
    |--------------------------------------------------------------------------
    |
    | A closed row used to leave the browser fully signed in, so the
    | heartbeat 404'd forever and login-session-heartbeat.js reloaded the
    | page every few seconds without ever reaching the login screen.
    */

    public function test_a_session_superseded_by_a_login_elsewhere_is_signed_out(): void
    {
        $user = $this->signedInUser();
        $session = $this->openSessionFor($user);

        $session->forceFill([
            'logout_at' => now()->subMinute(),
            'logout_reason' => 'new_login',
        ])->save();

        $this->actingAs($user)
            ->withSession(['login_session_id' => $session->id])
            ->get('/admin')
            ->assertRedirect('/admin/login');

        $this->assertGuest();

        // The reason it was closed with must survive being acted on.
        $this->assertSame('new_login', $session->refresh()->logout_reason);
    }

    public function test_a_session_closed_by_the_sweeper_is_signed_out_on_the_next_request(): void
    {
        $user = $this->signedInUser();
        $session = $this->openSessionFor($user, now()->subMinutes(40));

        $this->artisan('sessions:close-idle')->assertExitCode(0);

        $this->actingAs($user)
            ->withSession(['login_session_id' => $session->id])
            ->get('/admin')
            ->assertRedirect('/admin/login');

        $this->assertGuest();
    }

    /**
     * The full loop the browser walks: the heartbeat reports the closed
     * row, the script reloads ONCE, and that page request is what the
     * middleware turns into a real sign-out.
     *
     * The heartbeat route deliberately does not carry the panel's
     * authMiddleware — running EnforceIdleTimeout on it would stamp
     * last_activity_at on every beat and no tab could ever go idle — so
     * reporting, not signing out, is all it does.
     */
    public function test_a_closed_session_is_reported_by_the_heartbeat_and_ended_by_the_next_page_request(): void
    {
        $user = $this->signedInUser();
        $session = $this->openSessionFor($user);

        $session->forceFill([
            'logout_at' => now()->subMinute(),
            'logout_reason' => 'new_login',
        ])->save();

        $this->actingAs($user)
            ->withSession(['login_session_id' => $session->id])
            ->postJson('/login-session/heartbeat', ['active' => true, 'interacted' => true])
            ->assertNotFound();

        $this->actingAs($user)
            ->withSession(['login_session_id' => $session->id])
            ->get('/admin')
            ->assertRedirect('/admin/login');

        $this->assertGuest();
    }

    /**
     * A browser that signed in before login sessions were tracked has no
     * `login_session_id` at all, and must keep working exactly as before.
     */
    public function test_a_browser_with_no_tracked_login_session_is_left_alone(): void
    {
        $user = $this->signedInUser();

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk();

        $this->assertAuthenticated();
    }

    /*
    |--------------------------------------------------------------------------
    | Heartbeat
    |--------------------------------------------------------------------------
    */

    public function test_the_heartbeat_advances_the_idle_clock_only_on_real_interaction(): void
    {
        $user = $this->signedInUser();
        $session = $this->openSessionFor($user, now()->subMinutes(5));
        $before = $session->last_activity_at->copy();

        // Tab visible, but nobody touched anything.
        $this->actingAs($user)
            ->withSession(['login_session_id' => $session->id])
            ->postJson('/login-session/heartbeat', ['active' => true, 'interacted' => false])
            ->assertOk()
            ->assertJson(['interacted' => false]);

        $this->assertTrue(
            $session->refresh()->last_activity_at->equalTo($before),
            'A visible-but-untouched tab must not count as activity.',
        );

        // Now the user actually does something.
        $this->actingAs($user)
            ->withSession(['login_session_id' => $session->id])
            ->postJson('/login-session/heartbeat', ['active' => true, 'interacted' => true])
            ->assertOk();

        $this->assertTrue($session->refresh()->last_activity_at->greaterThan($before));
    }

    /**
     * Screen time is a separate measure and must keep behaving exactly as
     * it did — a visible tab accrues it whether or not anyone types.
     */
    public function test_screen_time_still_accrues_for_a_visible_tab(): void
    {
        $user = $this->signedInUser();

        $session = $this->openSessionFor($user);
        $session->forceFill(['last_seen_at' => now()->subSeconds(30)])->save();

        $this->actingAs($user)
            ->withSession(['login_session_id' => $session->id])
            ->postJson('/login-session/heartbeat', ['active' => true, 'interacted' => false])
            ->assertOk();

        $this->assertGreaterThan(600, $session->refresh()->screen_time_seconds);
    }

    public function test_the_heartbeat_reports_the_remaining_idle_seconds(): void
    {
        // Frozen to a whole second: the timestamp column has no
        // sub-second precision, so with a fractional "now" the stored
        // last_activity_at reads back rounded down and the countdown
        // comes out one second short.
        $this->freezeSecond();

        $user = $this->signedInUser();
        $session = $this->openSessionFor($user, now()->subMinutes(10));

        $this->actingAs($user)
            ->withSession(['login_session_id' => $session->id])
            ->postJson('/login-session/heartbeat', ['active' => true, 'interacted' => false])
            ->assertOk()
            ->assertJson([
                'idle_timeout_minutes' => 15,
                'seconds_until_logout' => 300,
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Sweeper
    |--------------------------------------------------------------------------
    */

    public function test_the_sweeper_closes_abandoned_sessions_at_the_moment_they_went_idle(): void
    {
        $user = $this->signedInUser();
        $wentIdleAt = now()->subMinutes(40);
        $session = $this->openSessionFor($user, $wentIdleAt);

        $this->artisan('sessions:close-idle')->assertExitCode(0);

        $session->refresh();

        $this->assertSame(UserLoginSession::REASON_SESSION_TIMEOUT, $session->logout_reason);

        // Closed at last interaction + 15 minutes, NOT at "now" — the
        // sweeper must not credit the gap until it happened to run.
        $this->assertSame(
            $wentIdleAt->copy()->addMinutes(15)->format('Y-m-d H:i'),
            $session->logout_at->format('Y-m-d H:i'),
        );
    }

    public function test_the_sweeper_leaves_active_sessions_and_already_closed_ones_alone(): void
    {
        $user = $this->signedInUser();

        $active = $this->openSessionFor($user, now()->subMinutes(2));

        $alreadyClosed = $this->openSessionFor($user, now()->subHours(2));
        $alreadyClosed->forceFill([
            'logout_at' => now()->subHour(),
            'logout_reason' => 'logout',
        ])->save();

        $this->artisan('sessions:close-idle')->assertExitCode(0);

        $this->assertNull($active->refresh()->logout_at);
        $this->assertSame('logout', $alreadyClosed->refresh()->logout_reason);
    }

    public function test_the_sweeper_does_nothing_when_idle_logout_is_disabled(): void
    {
        config(['session.idle_timeout' => 0]);

        $user = $this->signedInUser();
        $session = $this->openSessionFor($user, now()->subDay());

        $this->artisan('sessions:close-idle')->assertExitCode(0);

        $this->assertNull($session->refresh()->logout_at);
    }

    public function test_the_activity_badge_reflects_real_idleness(): void
    {
        $user = $this->signedInUser();

        $this->assertTrue($this->openSessionFor($user, now()->subMinutes(2))->is_active);
        $this->assertFalse($this->openSessionFor($user, now()->subMinutes(30))->is_active);
    }
}
