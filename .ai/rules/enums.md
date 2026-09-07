---
paths:
  - app/Enums/CommitmentStage.php
---

# Enums

## Approval/Disbursal is a label-only rename, and Disbursal is the default stage
The Daily Commitment module calls the top two rungs "Approval" and "Disbursal". This lives only in CommitmentStage::label() — the enum cases and stored values stay Approved/'approved' and Disbursed/'disbursed', as do the LMS journey statuses and stage-history strings. Do not rename the cases or the column values.

CommitmentStage::default() is Disbursed: every target and commitment form in the module (Monthly Target resource, MonthlyTargetPrompt rows and its "same for everyone" row, My Commitment, the Admin correction and set-monthly-target modals) starts there unless a record already says otherwise.
