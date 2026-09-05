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
