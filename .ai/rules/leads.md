---
paths:
  - 'app/Filament/Resources/Leads/**, app/Support/LeadListingPeriod.php, app/Filament/Actions/ListingStatusPicker.php'
---

# Leads

## My Leads listing: leads added in the topbar period, narrowed by the status beside "Export Leads"
User decision 2026-11-24, matching My Customers (.ai/rules/customers.md). App\Support\LeadListingPeriod::apply() is the single scope for the listing and therefore its export: leads whose created_at is in SelectedMonth::range(), narrowed to ListLeads::$leadStatus (URL `?status=`). Choices are CustomerAssignment::FOLLOW_UP_STATUSES plus "Other" (null or any value outside that list — old imports hold free text such as "after 7PM"), so the statuses add up to "All statuses". The button is App\Filament\Actions\ListingStatusPicker, shared with My Customers: an ActionGroup whose items are named "<property>_<Str::slug(status, '_')>" (leadStatus_call_back, customerStatus_sanctioned), store the choice on the page property and reset the page; it hides itself on components without that property. The table description states the period and status. Covered by tests/Feature/SelectedPeriodListingExportTest.php.
