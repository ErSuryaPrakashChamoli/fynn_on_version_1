<?php

namespace Tests\Feature;

use App\Filament\Pages\MyVotes;
use App\Filament\Resources\Polls\Pages\CreatePoll;
use App\Filament\Resources\Polls\PollResource;
use App\Filament\Resources\PollTypes\PollTypeResource;
use App\Livewire\PollPrompt;
use App\Models\Employee;
use App\Models\Poll;
use App\Models\PollRecipient;
use App\Models\PollType;
use App\Models\PollVote;
use App\Models\User;
use App\Services\Voting\PollService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Setting → Voting: the Admin or any supervisor above caller raises a poll
 * (company wide / roles / designations) with Admin-defined answer options;
 * recipients vote once, anonymously when asked; mandatory polls block the
 * panel until answered; the raiser sees results and (unless anonymous)
 * who chose what.
 */
class VotingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teamLeaderUser;

    private User $callerUser;

    private User $itUser;

    private PollService $polls;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Team Leader', 'Caller', 'IT'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->travelTo(Carbon::parse('2026-09-27 11:00:00'));

        $teamLeader = Employee::factory()->create(['designation' => Employee::DESIGNATION_TEAM_LEADER, 'exit_status' => 'no']);
        $caller = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'superviser_id' => $teamLeader->id, 'exit_status' => 'no']);

        $this->admin = User::factory()->create(['name' => 'Asha Admin']);
        $this->admin->assignRole('Admin');
        $this->teamLeaderUser = User::factory()->create(['employee_id' => $teamLeader->id, 'name' => 'Tarun Leader']);
        $this->teamLeaderUser->assignRole('Team Leader');
        $this->callerUser = User::factory()->create(['employee_id' => $caller->id, 'name' => 'Ravi Caller']);
        $this->callerUser->assignRole('Caller');
        $this->itUser = User::factory()->create(['name' => 'Ishaan IT']);
        $this->itUser->assignRole('IT');

        $this->polls = app(PollService::class);
    }

    public function test_the_migration_seeds_the_default_poll_types(): void
    {
        $this->assertSame(4, PollType::query()->count());
        $this->assertSame(['Good', 'Satisfactory', 'Bad'], $this->type('Feedback')->optionList());
        $this->assertFalse($this->type('Yes / No')->allow_comment);
        $this->assertTrue($this->type('Feedback')->hasReasons());
        $this->assertFalse($this->type('Yes / No')->hasReasons());
    }

    public function test_reasons_follow_the_answer_and_are_required_only_when_the_poll_asks(): void
    {
        $feedback = $this->type('Feedback');
        $this->assertSame(['Quality', 'Speed', 'Behaviour / support', 'Other'], PollType::reasonsFor($feedback->reasonList(), 'Good'));
        $this->assertSame(['Quality', 'Delay', 'Behaviour / support', 'Other'], PollType::reasonsFor($feedback->reasonList(), 'Bad'));
        $this->assertSame(['Other'], PollType::reasonsFor($feedback->reasonList(), 'Satisfactory'));

        $asking = $this->raise($this->admin, ['ask_reason' => true, 'reasons' => $feedback->reasonList()]);

        try {
            $this->polls->vote($asking, $this->callerUser, 'Bad');
            $this->fail('A vote without a reason was accepted on a poll that asks for one.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }

        try {
            $this->polls->vote($asking, $this->callerUser, 'Bad', null, 'Speed');
            $this->fail('A reason that belongs to another answer was accepted.');
        } catch (ValidationException) {
        }

        $vote = $this->polls->vote($asking, $this->callerUser, 'Bad', 'Late twice.', 'Delay');
        $this->assertSame('Delay', $vote->reason);
        $this->assertSame(['Bad' => ['Delay' => 1]], $this->polls->reasonBreakdown($asking)->map->all()->all());

        // The same type without the toggle: no reason asked, none stored.
        $silent = $this->raise($this->admin, ['ask_reason' => false, 'reasons' => null]);
        $this->assertNull($this->polls->vote($silent, $this->callerUser, 'Bad', null, 'Delay')->reason);
    }

    public function test_the_create_page_copies_the_types_reasons_only_when_the_raiser_asks_for_them(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreatePoll::class)
            ->fillForm([
                'title' => 'Dialer feedback',
                'question' => 'How is the new dialer?',
                'poll_type_id' => $this->type('Feedback')->id,
                'ask_reason' => true,
                'audience' => Poll::AUDIENCE_COMPANY,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $poll = Poll::query()->where('title', 'Dialer feedback')->firstOrFail();
        $this->assertTrue($poll->ask_reason);
        $this->assertSame(['Quality', 'Delay', 'Behaviour / support', 'Other'], $poll->reasonsFor('Bad'));

        Livewire::test(CreatePoll::class)
            ->fillForm([
                'title' => 'Training attendance',
                'question' => 'Will you attend?',
                'poll_type_id' => $this->type('Yes / No')->id,
                'ask_reason' => true,
                'audience' => Poll::AUDIENCE_COMPANY,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        // A type without reasons cannot ask for one, whatever the toggle says.
        $this->assertFalse(Poll::query()->where('title', 'Training attendance')->firstOrFail()->ask_reason);
    }

    public function test_the_mandatory_prompt_asks_for_a_reason_once_an_answer_is_picked(): void
    {
        $poll = $this->raise($this->admin, ['title' => 'Reason poll', 'is_mandatory' => true, 'ask_reason' => true, 'reasons' => $this->type('Feedback')->reasonList()]);
        $recipientId = PollRecipient::query()->where('poll_id', $poll->id)->where('user_id', $this->callerUser->id)->value('id');
        $this->actingAs($this->callerUser);

        $prompt = Livewire::test(PollPrompt::class)
            ->assertSee('Reason poll')
            ->assertDontSee('Select a reason')
            ->set('option', 'Bad')
            ->assertSee('Select a reason')
            ->assertSee('Delay')
            ->assertDontSee('Speed');

        $prompt->call('submitVote', $recipientId)->assertHasErrors(['reason']);

        $prompt->set('reason', 'Delay')
            ->call('submitVote', $recipientId)
            ->assertHasNoErrors()
            ->assertDontSee('Reason poll');

        $this->assertSame('Delay', PollVote::query()->where('poll_id', $poll->id)->value('reason'));
    }

    public function test_supervisors_and_admin_raise_polls_and_callers_only_vote(): void
    {
        $this->actingAs($this->callerUser);
        $this->assertFalse(PollResource::canAccess());
        $this->assertFalse(PollTypeResource::canAccess());
        $this->assertTrue(MyVotes::canAccess());

        $this->actingAs($this->teamLeaderUser);
        $this->assertTrue(PollResource::canAccess());
        $this->assertFalse(PollTypeResource::canAccess());

        $this->actingAs($this->admin);
        $this->assertTrue(PollResource::canAccess());
        $this->assertTrue(PollTypeResource::canAccess());
    }

    public function test_a_team_leader_raises_a_company_wide_mandatory_poll_from_the_create_page(): void
    {
        $this->actingAs($this->teamLeaderUser);

        Livewire::test(CreatePoll::class)
            ->fillForm([
                'title' => 'Canteen feedback',
                'question' => 'How was this month\'s canteen food?',
                'poll_type_id' => $this->type('Feedback')->id,
                'allow_comment' => true,
                'is_mandatory' => true,
                'is_anonymous' => false,
                'expires_at' => '2026-09-30 18:00',
                'audience' => Poll::AUDIENCE_COMPANY,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $poll = Poll::query()->firstOrFail();

        $this->assertSame($this->teamLeaderUser->id, $poll->created_by);
        $this->assertSame(['Good', 'Satisfactory', 'Bad'], $poll->optionList());
        $this->assertTrue($poll->is_mandatory);
        $this->assertSame(4, $poll->recipients_count);
        $this->assertSame(4, PollRecipient::query()->where('poll_id', $poll->id)->count());
        $this->assertSame('voting', $this->callerUser->notifications()->first()->category);
        $this->assertStringContainsString('Your vote is required', $this->callerUser->notifications()->first()->data['title']);
    }

    public function test_role_and_designation_audiences_reach_only_their_people(): void
    {
        $byRole = $this->raise($this->admin, ['audience' => Poll::AUDIENCE_ROLES, 'audience_roles' => ['IT']]);
        $this->assertSame([$this->itUser->id], $byRole->recipients()->pluck('user_id')->all());

        $byDesignation = $this->raise($this->admin, ['audience' => Poll::AUDIENCE_DESIGNATIONS, 'audience_designations' => [Employee::DESIGNATION_CALLER]]);
        $this->assertSame([$this->callerUser->id], $byDesignation->recipients()->pluck('user_id')->all());
        $this->assertSame('Designations: Caller', $byDesignation->audienceLabel());
    }

    public function test_a_recipient_votes_once_with_a_valid_option(): void
    {
        $poll = $this->raise($this->teamLeaderUser);

        $vote = $this->polls->vote($poll, $this->callerUser, 'Good', 'Loved the biryani.');

        $this->assertSame($this->callerUser->id, $vote->user_id);
        $this->assertSame('Good', $vote->option);
        $this->assertSame('Loved the biryani.', $vote->comment);
        $this->assertNotNull(PollRecipient::query()->where('poll_id', $poll->id)->where('user_id', $this->callerUser->id)->value('voted_at'));
        $this->assertSame(1, $poll->fresh()->votes_count);
        $this->assertSame(0, $this->callerUser->unreadNotifications()->count());

        try {
            $this->polls->vote($poll, $this->callerUser, 'Bad');
            $this->fail('A second vote was accepted.');
        } catch (AuthorizationException) {
        }

        try {
            $this->polls->vote($poll, $this->teamLeaderUser, 'Excellent');
            $this->fail('An option outside the poll was accepted.');
        } catch (ValidationException) {
        }

        $this->assertSame(1, PollVote::query()->count());
    }

    public function test_voting_is_refused_on_expired_polls_and_for_people_outside_the_audience(): void
    {
        $expired = $this->raise($this->admin, ['expires_at' => now()->subMinute()]);

        try {
            $this->polls->vote($expired, $this->callerUser, 'Good');
            $this->fail('A vote on an expired poll was accepted.');
        } catch (AuthorizationException) {
        }

        $itOnly = $this->raise($this->admin, ['audience' => Poll::AUDIENCE_ROLES, 'audience_roles' => ['IT']]);

        $this->expectException(AuthorizationException::class);
        $this->polls->vote($itOnly, $this->callerUser, 'Good');
    }

    public function test_anonymous_votes_carry_no_voter_but_participation_is_still_tracked(): void
    {
        $poll = $this->raise($this->admin, ['is_anonymous' => true]);

        $vote = $this->polls->vote($poll, $this->callerUser, 'Bad', 'Too oily.');

        $this->assertNull($vote->user_id);
        $this->assertSame('Anonymous', $vote->voterLabel());
        $this->assertTrue(PollRecipient::query()->where('poll_id', $poll->id)->where('user_id', $this->callerUser->id)->first()->hasVoted());

        $this->expectException(AuthorizationException::class);
        $this->polls->vote($poll, $this->callerUser, 'Good');
    }

    public function test_results_count_votes_per_option_in_the_polls_order(): void
    {
        $poll = $this->raise($this->admin);

        $this->polls->vote($poll, $this->callerUser, 'Good');
        $this->polls->vote($poll, $this->teamLeaderUser, 'Good');
        $this->polls->vote($poll, $this->itUser, 'Bad');

        $results = $this->polls->results($poll)->keyBy('option');

        $this->assertSame(2, $results['Good']['votes']);
        $this->assertSame(66.7, $results['Good']['percent']);
        $this->assertSame(0, $results['Satisfactory']['votes']);
        $this->assertSame(1, $results['Bad']['votes']);
        $this->assertSame('3 / 4 voted', $poll->fresh()->participationLabel());
    }

    public function test_the_admin_sees_every_poll_and_a_raiser_only_their_own(): void
    {
        $mine = $this->raise($this->teamLeaderUser);
        $theirs = $this->raise($this->admin);

        $this->actingAs($this->teamLeaderUser);
        $this->assertSame([$mine->id], PollResource::getEloquentQuery()->pluck('id')->all());
        $this->assertFalse(PollResource::canView($theirs));

        $this->actingAs($this->admin);
        $this->assertEqualsCanonicalizing([$mine->id, $theirs->id], PollResource::getEloquentQuery()->pluck('id')->all());
        $this->assertTrue(PollResource::canView($mine));
    }

    public function test_a_mandatory_poll_blocks_until_voted_and_an_optional_one_does_not(): void
    {
        $optional = $this->raise($this->admin, ['title' => 'Optional lunch poll']);
        $this->actingAs($this->callerUser);

        Livewire::test(PollPrompt::class)->assertDontSee('Optional lunch poll');

        $mandatory = $this->raise($this->admin, ['title' => 'Mandatory safety poll', 'is_mandatory' => true, 'poll_type_id' => $this->type('Yes / No')->id, 'options' => ['Yes', 'No'], 'allow_comment' => false]);
        $recipientId = PollRecipient::query()->where('poll_id', $mandatory->id)->where('user_id', $this->callerUser->id)->value('id');

        $prompt = Livewire::test(PollPrompt::class)
            ->assertSee('Mandatory safety poll')
            ->assertSee('Your vote is required')
            ->assertDontSee('Comment (optional)');

        $prompt->call('submitVote', $recipientId)->assertHasErrors(['option']);
        $this->assertSame(0, PollVote::query()->count());

        $prompt->set('option', 'Yes')
            ->call('submitVote', $recipientId)
            ->assertHasNoErrors()
            ->assertDontSee('Mandatory safety poll');

        $this->assertSame('Yes', PollVote::query()->where('poll_id', $mandatory->id)->value('option'));
        $this->assertSame($this->callerUser->id, PollVote::query()->where('poll_id', $mandatory->id)->value('user_id'));
    }

    public function test_a_closed_mandatory_poll_stops_blocking(): void
    {
        $poll = $this->raise($this->admin, ['title' => 'Closed poll', 'is_mandatory' => true]);
        $this->actingAs($this->callerUser);
        Livewire::test(PollPrompt::class)->assertSee('Closed poll');

        $poll->update(['is_active' => false]);
        Livewire::test(PollPrompt::class)->assertDontSee('Closed poll');
    }

    public function test_the_my_votes_page_lists_polls_and_records_a_vote(): void
    {
        $poll = $this->raise($this->admin, ['title' => 'Lunch poll']);
        $recipient = PollRecipient::query()->where('poll_id', $poll->id)->where('user_id', $this->callerUser->id)->firstOrFail();

        $this->actingAs($this->callerUser);

        Livewire::test(MyVotes::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$recipient])
            ->assertSee('Lunch poll')
            ->callAction(TestAction::make('vote')->table($recipient), ['option' => 'Satisfactory', 'comment' => 'Fine.'])
            ->assertNotified('Thank you, your vote is recorded');

        $this->assertSame('Satisfactory', PollVote::query()->where('poll_id', $poll->id)->value('option'));
        $this->assertTrue($recipient->fresh()->hasVoted());
    }

    public function test_the_sidebar_shows_my_votes_to_everyone_and_the_poll_screens_to_raisers(): void
    {
        $this->actingAs($this->itUser)
            ->followingRedirects()
            ->get('/admin')
            ->assertOk()
            ->assertSee(MyVotes::getUrl(), escape: false)
            ->assertDontSee(PollResource::getUrl('index'), escape: false);

        $this->actingAs($this->admin)
            ->followingRedirects()
            ->get('/admin')
            ->assertOk()
            ->assertSee(PollResource::getUrl('index'), escape: false)
            ->assertSee(PollTypeResource::getUrl('index'), escape: false);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function raise(User $creator, array $attributes = []): Poll
    {
        $type = $this->type('Feedback');

        $poll = Poll::query()->create([
            'title' => 'Canteen feedback',
            'question' => 'How was the food?',
            'poll_type_id' => $type->id,
            'options' => $type->optionList(),
            'allow_comment' => true,
            'is_mandatory' => false,
            'is_anonymous' => false,
            'audience' => Poll::AUDIENCE_COMPANY,
            'is_active' => true,
            'expires_at' => now()->addDays(3),
            'created_by' => $creator->id,
            ...$attributes,
        ]);

        $this->polls->publish($poll);

        return $poll->fresh();
    }

    private function type(string $name): PollType
    {
        return PollType::query()->where('name', $name)->firstOrFail();
    }
}
