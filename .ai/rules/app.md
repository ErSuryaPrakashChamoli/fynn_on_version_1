---
paths:
  - 'app/**/Customer*.php'
---

# App

## journey_status never equals 'finalized' — use disbursal_finalized for journey completion
The customers.journey_status column only ever holds: sfl, underwriting, approved, not_approved, sanctioned, dropped, carry_forward. It never becomes 'finalized' — that string appears only as a UI-lock check in CustomerForm, not as a stored value. To check whether a customer's journey is complete/pending, use the boolean `disbursal_finalized` column (set true by CustomerJourneyService::sanction()/finalize()), as JourneySlaService and JourneyModule::forCustomer() already do. A prior bug in CustomerStats.php compared journey_status === 'finalized', which always evaluated false and made "Completed Journey" permanently read 0 and "Pending Journey" permanently read 100%.

## Eligibility changes only through CustomerEligibilityService after creation
Since 2026-09-21 the eligibility_status select is disabled on the edit form. After a file is created, eligibility changes only through CustomerEligibilitySection, which runs every write through CustomerEligibilityService. The rules: Eligible is final for everyone, Admin included, and the file goes to SFL. Not Eligible can only become Eligible when an Admin approves a CustomerEligibilityRequest; the owner or anyone above them can raise one, and a file can have only one pending request at a time. Consent Pending can be changed at any time by the owner's chain or an Admin. Every event, including creation (CreateCustomer::afterCreate), writes a customer_eligibility_logs row and notifies the owner's chain and the Admins. The section sits on both the form and the infolist because callers only get the view page. Never write eligibility_status directly. Covered by tests/Feature/CustomerEligibilityWorkflowTest.php.
