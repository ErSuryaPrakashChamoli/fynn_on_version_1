---
paths:
  - 'app/Services/CustomerCashbackService.php, app/Models/CustomerCashbackRequest.php, app/Filament/Resources/CustomerCashbackRequests/**, app/Filament/Resources/Customers/Schemas/CustomerCashbackSection.php, app/Enums/Cashback*.php, app/Services/CustomerJourneyService.php'
---

# Cashback

## customers.cashback follows the file's live cashback form — never type it
User decision 2026-11-20: the paper cashback "Acknowledgment" voucher is now Customers → Cashback Forms (customer_cashback_requests). Step 4 of the journey has a declaration (customers.cashback_option: none / cashback / expected); the old typed "Cashback Given" input is gone and the Step 4 amount is read-only (dehydrated(false)). CustomerJourneyService::sanction() refuses a disbursed file without a declaration, or with cashback / expected but no live form of that type (CustomerCashbackService::cashbackForDeclaration).

CustomerCashbackService is the only writer. A file has at most one live form (pending_manager, pending_admin, approved); syncCustomer() sets customers.cashback to the live form's amount (approved_amount ?? amount) and 0 when none is live — pending forms already count, so the achievement/incentive maths (which read customers.cashback, MIS cashback first) need no change. Flow: Team Leader → Manager → Admin; a Manager/Cluster/Business Head's form starts at the Admin; the Admin's own is approved at once. Only an unpaid Expected cashback can be retrieved (TL and up on the file's chain) → cashback 0. After approval only the Admin changes the amount (changeAmount, logged with reason); Admin/Accounts record payment (Paid needs mode + date + UTR unless cash; On Hold needs remarks). CustomerEditRequestService refuses the `cashback` field on files with a live form. Settlement sales_cashback is a snapshot and is not re-synced. Covered by tests/Feature/CustomerCashbackFormTest.php.
