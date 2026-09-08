---
paths:
  - 'app/Filament/Pages/DailyCommitment*.php'
---

# Pages

## A daily commitment is locked once given — Admin-only corrections
DailyCommitment::isEditableBy() returns true only for an Admin. A morning commitment cannot be changed or withdrawn by its owner, their Team Leader, or anyone else once it exists.

Enforced in two places, both needed: MyDailyCommitment disables the stage/amount/count/remarks fields via isCommitmentLocked(), AND save() re-checks before writing, so a crafted Livewire request cannot rewrite the number after the fact. An Admin corrects a commitment through DailyCommitmentDetail::editCommitmentAction(), which requires a reason and writes a `admin_correction` log row.

The end-of-day fulfilment (daily_commitment_entries) is NOT covered by this lock — the owner keeps editing it until they submit, and may reopen it.

DailyCommitmentDetail is a parameterised page: the slug stays plain ('daily-commitment-detail') so the route NAME is unchanged, and getRoutePath() appends '/{record}'. mount() aborts 403 unless DailyCommitmentService::canView() passes. Link to it with DailyCommitmentDetail::getUrl(['record' => $id]).

Dashboard/Team View rows navigate to it via x-on:click + window.Livewire.navigate; any interactive control inside such a row needs x-on:click.stop on its cell (see the Open and Set-expected-OTP cells in team-view.blade.php) or it will fire the row navigation too.

## The 18:30 declaration is compulsory and cannot be closed empty
MyDailyCommitment is deliberately the one page DailyCommitmentPrompt never covers (it checks the route name) and the one page EnsureDailyCommitmentIsDeclared always permits — it is the only way out of the block, so covering or redirecting it would lock the user out entirely.

submitFinalStatus() refuses an empty entry list: every rupee declared has to be backed by a named case. A genuinely blank day goes through declareNothing(), which demands a reason of at least 10 characters and writes it to daily_commitments.declaration_note before submitting. Without that reason path an employee is permanently locked out on a nil day; without the 10-character floor "nothing today" becomes the quick way past the block. Keep both.

The morning prompt (DailyCommitmentPrompt::commit) creates a commitment but never rewrites an existing one — the once-given lock in DailyCommitment::isEditableBy() still holds, and the prompt re-checks the gate's own reason before writing.

## Closing a day is a choice of two paths — the case list is never shown unprompted
Supersedes the earlier note that a nil day needs a 10-character reason. Meeting the commitment is not compulsory; declaring the outcome is.

MyDailyCommitment::$declarationMode gates the whole evening half: null until the employee says which path, then 'cases' (the customer repeater) or 'failed' (a zero against every rung). The customer list is deliberately not put in front of somebody who had no customers — chooseDeclarationMode() is the only way in, and it rejects any value other than those two. fillFromCommitment() pre-selects 'cases' when the day already has entries.

The failed path records $nilStages — one figure per CommitmentStage::ladder() rung, all of which must be 0. A non-zero value is refused and the employee is sent to the case list, because real business needs a customer and a mobile number behind it and the zeros path has neither. The note (declaration_note) is now OPTIONAL; the zeros themselves are the deliberate act that stops a day being closed carelessly.

submitFinalStatus() still refuses an empty entry list — that is the 'cases' path's own guard, not a substitute for the failed path.
