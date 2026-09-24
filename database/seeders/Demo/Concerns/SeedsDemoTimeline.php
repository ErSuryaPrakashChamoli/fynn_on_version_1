<?php

namespace Database\Seeders\Demo\Concerns;

use App\Models\User;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Lets a demo seeder replay history through the application's real code.
 *
 * Every service, observer and model hook stamps "now" and "who" on what it
 * writes — stage histories, journey audits, activity log rows, settlement
 * numbers, commitment submissions. Rather than back-dating those rows by
 * hand afterwards, the seeder moves the clock to the moment the event
 * happened and signs in as the person who did it, then calls the same code
 * the panel would. The clock is never moved past the real present.
 */
trait SeedsDemoTimeline
{
    /**
     * The real "now" at the moment seeding started — the ceiling for every
     * replayed event.
     */
    protected function realNow(): Carbon
    {
        return DemoSeedState::realNow();
    }

    /**
     * A moment on $day at $hour:$minute, pulled back to just before the real
     * present when it would otherwise land in the future.
     */
    protected function momentOn(Carbon $day, int $hour, int $minute = 0): Carbon
    {
        $moment = $day->copy()->setTime($hour, $minute, fake()->numberBetween(0, 59));
        $ceiling = $this->realNow()->copy()->subMinutes(2);

        return $moment->greaterThan($ceiling) ? $ceiling : $moment;
    }

    /**
     * Run $callback with the clock at $moment and $user signed in, then put
     * both back.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function replay(Carbon $moment, ?User $user, Closure $callback): mixed
    {
        $previousUser = Auth::user();

        Carbon::setTestNow($moment);

        if ($user !== null) {
            Auth::setUser($user);
        }

        try {
            return $callback();
        } finally {
            Carbon::setTestNow();

            if ($previousUser !== null) {
                Auth::setUser($previousUser);
            } else {
                Auth::forgetUser();
            }
        }
    }

    /**
     * Whether $day is a working day for the sales floor (Monday–Saturday).
     */
    protected function isWorkingDay(Carbon $day): bool
    {
        return ! $day->isSunday();
    }
}
