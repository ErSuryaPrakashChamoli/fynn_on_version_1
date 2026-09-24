<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use App\Models\UserLoginSession;
use Database\Seeders\Demo\Concerns\SeedsDemoTimeline;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The login log: one working session per staff login per working day for
 * the last four weeks, which is also what the Daily Commitment module
 * counts as attendance (DailyCommitmentService::presentDays()). A share of
 * sessions ends on the idle timeout, the rest on a manual logout; nobody
 * is left "online" so the idle sweeper has nothing to close.
 */
class DemoLoginSessionSeeder extends Seeder
{
    use SeedsDemoTimeline;

    /** @var list<string> */
    protected const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
        'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Mobile Safari/537.36',
    ];

    public function run(): void
    {
        $today = $this->realNow()->copy()->startOfDay();
        $users = User::query()->with('employee')->orderBy('id')->get();

        foreach ($users as $user) {
            for ($day = $today->copy()->subDays(27); $day->lessThanOrEqualTo($today); $day->addDay()) {
                if (! $this->isWorkingDay($day) || ! $this->wasOnRolls($user, $day) || fake()->boolean(8)) {
                    continue;
                }

                $this->seedSession($user, $day);
            }
        }
    }

    protected function wasOnRolls(User $user, Carbon $day): bool
    {
        $employee = $user->employee;

        if ($employee === null) {
            return true;
        }

        if ($employee->doj && Carbon::parse($employee->doj)->greaterThan($day)) {
            return false;
        }

        return ! ($employee->exit_date && Carbon::parse($employee->exit_date)->lessThan($day));
    }

    protected function seedSession(User $user, Carbon $day): void
    {
        $login = $this->momentOn($day, 9, fake()->numberBetween(0, 40));

        if ($login->greaterThanOrEqualTo($this->realNow()->copy()->subMinutes(5))) {
            return;
        }

        $timedOut = fake()->boolean(25);
        $logout = $day->copy()->setTime(fake()->numberBetween(17, 19), fake()->numberBetween(0, 59));
        $ceiling = $this->realNow()->copy()->subMinutes(3);

        if ($logout->greaterThan($ceiling)) {
            $logout = $ceiling;
        }

        $lastActivity = $timedOut
            ? $logout->copy()->subMinutes((int) config('session.idle_timeout', 30))
            : $logout->copy();

        if ($lastActivity->lessThan($login)) {
            $lastActivity = $login->copy();
        }

        $screenTime = (int) max(0, $login->diffInSeconds($lastActivity) * fake()->randomFloat(2, 0.55, 0.9));

        $this->replay($login, null, fn () => UserLoginSession::query()->create([
            'user_id' => $user->id,
            'employee_id' => $user->employee_id,
            'session_id' => Str::random(40),
            'login_at' => $login,
            'logout_at' => $logout,
            'last_seen_at' => $lastActivity,
            'last_activity_at' => $lastActivity,
            'screen_time_seconds' => $screenTime,
            'ip_address' => '10.20.'.fake()->numberBetween(1, 30).'.'.fake()->numberBetween(2, 250),
            'user_agent' => fake()->randomElement(self::USER_AGENTS),
            'logout_reason' => $timedOut ? UserLoginSession::REASON_SESSION_TIMEOUT : 'logout',
        ]));
    }
}
