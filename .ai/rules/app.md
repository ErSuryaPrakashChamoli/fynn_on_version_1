---
paths:
  - 'app/**/Customer*.php'
---

# App

## journey_status never equals 'finalized' — use disbursal_finalized for journey completion
The customers.journey_status column only ever holds: sfl, underwriting, approved, not_approved, sanctioned, dropped, carry_forward. It never becomes 'finalized' — that string appears only as a UI-lock check in CustomerForm, not as a stored value. To check whether a customer's journey is complete/pending, use the boolean `disbursal_finalized` column (set true by CustomerJourneyService::sanction()/finalize()), as JourneySlaService and JourneyModule::forCustomer() already do. A prior bug in CustomerStats.php compared journey_status === 'finalized', which always evaluated false and made "Completed Journey" permanently read 0 and "Pending Journey" permanently read 100%.
