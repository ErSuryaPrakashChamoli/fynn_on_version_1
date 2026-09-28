---
paths:
  - 'app/Services/CustomerEditRequestService.php, app/Support/CustomerEditableFields.php, app/Filament/Resources/CustomerEditRequests/**'
---

# Customer Edit Requests

## Customer edit requests: owner's chain asks per section, Admin approves, approval writes the file and logs it
Since 2026-09-27 (Request → Customer Edit Requests, plus a "Request edit" button on ViewCustomer). Who raises: CustomerEditRequestService::canRaiseOn = CustomerEligibilityService::canWorkOn (owner, anyone above, continuity stand-in, Admin). Only the Admin approves/rejects (reject needs a note). A request = one section + N fields; the ONLY editable fields are those in App\Support\CustomerEditableFields (grouped as the journey form's sections, dropdown choices mirror CustomerForm — the bank list now lives in CustomerForm::bankOptions(), shared by both). Deliberately excluded: eligibility_status (eligibility workflow only), journey_status (computed), assign_to (reassignment flow) — do not add them. Validation in the service, not the form: field must belong to the section, select value must be a listed choice, amount numeric >= 0, value must differ from current, no duplicate field, no second pending request for the same field on the file. Approve locks the customer, writes every value in ONE save (cast via CustomerEditableFields::cast), stores overwritten_value + applied_at per item, and writes an activity() entry with before/after; items keep current_value (at raise) vs overwritten_value (at approval). Notifications category NotificationCategory::CustomerEdit. Covered by tests/Feature/CustomerEditRequestTest.php.
