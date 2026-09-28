---
paths:
  - 'app/Services/Voting/**, app/Filament/Resources/PollTypes/**, app/Filament/Resources/Polls/**, app/Livewire/PollPrompt.php, app/Filament/Pages/MyVotes.php'
---

# Livewire Filament Pages

## Poll reason dropdown: defined per type by the Admin (per answer or any answer), asked per poll by a toggle, snapshotted on the poll
Since 2026-09-27: poll_types.reasons is a list of {option: string|null, reason} (null / PollType::ANY_OPTION = any answer), edited only by the Admin in PollTypeForm's Repeater. A raiser toggles polls.ask_reason per poll; CreatePoll copies the type's reasonList() into polls.reasons only when the toggle is on AND the type has reasons (a type without reasons can never ask). Poll::reasonsFor($option) = reasons tied to that answer + any-answer ones; PollService::vote() requires a listed reason when that list is non-empty and stores NULL otherwise. The dropdown is shown only once an answer is picked (PollPrompt uses wire:model.live on option and resets reason; MyVotes uses a live Select + Get). VotingSeeder must tolerate the reasons column being absent (the create_voting_tables migration runs it before add_reasons_to_voting_tables adds the column, which then backfills the Feedback defaults). Results page lists reasons per answer via PollService::reasonBreakdown().
