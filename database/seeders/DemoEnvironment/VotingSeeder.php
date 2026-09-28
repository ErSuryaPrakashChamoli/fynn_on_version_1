<?php

namespace Database\Seeders\DemoEnvironment;

use App\Models\Poll;
use App\Models\PollType;
use App\Models\User;
use App\Services\Voting\PollService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * The Voting & Feedback submodule in the client demo: a closed anonymous
 * poll with results to browse, a mandatory poll everyone has already
 * answered (so no persona is blocked on sign-in), and an optional poll
 * still open for the personas to vote on from My Votes.
 */
class VotingSeeder extends Seeder
{
    private const COMMENTS = [
        'Good' => ['Much better than last quarter.', 'Keep it up.', 'Happy with it.'],
        'Satisfactory' => ['Okay, could be quicker.', 'Fine overall.'],
        'Bad' => ['Needs attention.', 'Not working for our floor.'],
    ];

    public function run(DemoWorld $world): void
    {
        $service = app(PollService::class);
        $adminId = $world->personaUserIds['admin'] ?? null;
        $managerId = $world->personaUserIds['manager'] ?? $adminId;

        if (! $adminId) {
            return;
        }

        $feedback = PollType::query()->where('name', 'Feedback')->first();
        $yesNo = PollType::query()->where('name', 'Yes / No')->first();

        if (! $feedback || ! $yesNo) {
            return;
        }

        // 1. Closed, anonymous: last month's canteen feedback, fully answered.
        Carbon::setTestNow($world->today->copy()->subDays(20)->setTime(10, 0));
        $closed = $this->raise($service, $adminId, $feedback, 'Canteen feedback — last month', 'How was the canteen food and service last month?', [
            'is_anonymous' => true,
            'ask_reason' => true,
            'reasons' => $feedback->reasonList(),
            'expires_at' => $world->today->copy()->subDays(13)->setTime(18, 0),
        ]);
        $this->castVotes($service, $closed, $world, ['Good' => 0.55, 'Satisfactory' => 0.3, 'Bad' => 0.15], 0.85);

        // 2. Mandatory, named: everyone has answered, so nobody is blocked.
        Carbon::setTestNow($world->today->copy()->subDays(4)->setTime(9, 30));
        $mandatory = $this->raise($service, $managerId, $yesNo, 'Weekend training session', 'Will you attend the Saturday product training at 10 AM?', [
            'is_mandatory' => true,
            'allow_comment' => false,
            'expires_at' => $world->today->copy()->addDays(2)->setTime(18, 0),
        ]);
        $this->castVotes($service, $mandatory, $world, ['Yes' => 0.7, 'No' => 0.3], 1.0);

        // 3. Optional, open: the personas still have this one to vote on.
        Carbon::setTestNow($world->today->copy()->subDay()->setTime(11, 0));
        $open = $this->raise($service, $adminId, $feedback, 'New dialer — first week', 'How is the new dialer working for you so far?', [
            'ask_reason' => true,
            'reasons' => $feedback->reasonList(),
            'expires_at' => $world->today->copy()->addDays(6)->setTime(18, 0),
        ]);
        $this->castVotes($service, $open, $world, ['Good' => 0.5, 'Satisfactory' => 0.35, 'Bad' => 0.15], 0.4, skipPersonas: true);

        Carbon::setTestNow($world->now);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function raise(PollService $service, int $creatorId, PollType $type, string $title, string $question, array $attributes): Poll
    {
        $poll = Poll::query()->create([
            'title' => $title,
            'question' => $question,
            'poll_type_id' => $type->id,
            'options' => $type->optionList(),
            'allow_comment' => $type->allow_comment,
            'is_mandatory' => false,
            'is_anonymous' => false,
            'audience' => Poll::AUDIENCE_COMPANY,
            'is_active' => true,
            'created_by' => $creatorId,
            ...$attributes,
        ]);

        $service->publish($poll);

        return $poll->fresh();
    }

    /**
     * @param  array<string, float>  $weights  option => share of the votes
     */
    private function castVotes(PollService $service, Poll $poll, DemoWorld $world, array $weights, float $turnout, bool $skipPersonas = false): void
    {
        $personaIds = array_values($world->personaUserIds);
        $recipients = $poll->recipients()->with('user')->get();
        $raisedAt = $poll->created_at->copy();
        $closesAt = $poll->expires_at?->copy()->min($world->now) ?? $world->now;

        foreach ($recipients as $recipient) {
            $isPersona = in_array($recipient->user_id, $personaIds, true);

            if ($skipPersonas && $isPersona) {
                continue;
            }

            if (! $isPersona && ! $world->chance($turnout)) {
                continue;
            }

            $option = $world->weighted($weights);
            $comment = $poll->allow_comment && $world->chance(0.4)
                ? $world->pick(self::COMMENTS[$option] ?? ['No comment.'])
                : null;

            $seconds = max(60, (int) $raisedAt->diffInSeconds($closesAt));
            Carbon::setTestNow($raisedAt->copy()->addSeconds(mt_rand(60, $seconds)));

            $reasons = $poll->reasonsFor($option);
            $reason = $reasons !== [] ? $world->pick($reasons) : null;

            /** @var User $user */
            $user = $recipient->user;
            $service->vote($poll, $user, $option, $comment, $reason);
        }
    }
}
