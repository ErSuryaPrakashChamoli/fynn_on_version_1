---
paths:
  - 'app/Services/Cibil/**'
  - 'app/Filament/Resources/CibilReports/**'
  - 'app/Filament/Resources/CibilRequests/**'
  - 'app/Filament/Pages/Cibil*.php'
  - 'app/Support/Cibil*.php'
  - 'app/Models/CibilReport.php'
  - 'app/Models/CibilSetting.php'
  - 'config/cibil.php'
---

# CIBIL

## Every bureau request goes through CibilReportService::request() — never call CibilClient directly
Surepass calls are billable (2026-10-17). request() is the only path that checks, in order: the per-type permission (CibilAccess::canRequest), CibilSetting (requests start OFF on every environment), details + consent (consent_method required, not a bare tick) and that the PAN equals the file's own PAN; then, under Cache::lock per PAN+type, it settles stale pending rows to Uncertain, refuses while one is Pending or Uncertain-and-unverified, reuses the file's own successful report (CibilRequestType::satisfiedBy, reuse_window_days; null = no expiry), and only then inserts the Pending row and calls the client. Reuse is per file (customer_id / lead_id / customer_assignment_id) — never match records by PAN alone. A lead's reports reach its customer file only via linkConvertedLead() from CreateCustomer::afterCreate. Covered by tests/Feature/CibilRequestSafetyTest.php.

## The bureau (CIBIL or Experian) is part of every key
Since 2026-10-17 the user picks the bureau first on every new request (CibilRequestFields::bureau(), first section of the fetch form and first field of each profile action); "Refresh report" on a report page keeps that report's bureau. cibil_reports.bureau (App\Enums\CreditBureau, existing rows = cibil) joins PAN + type in the lock key, the in-flight / unresolved-uncertain checks and reuse — a CIBIL report never stands in for Experian or blocks it. User decision 2026-10-17: both bureaus are ALWAYS offered with every request type — the choice in the LMS has nothing to do with whether a bureau's API is set up. No per-bureau switch (an earlier cibil_enabled/experian_enabled pair was dropped) and no hiding types by configured endpoint; a bureau/type whose token or endpoint (config cibil.surepass / cibil.experian) is missing fails at send time as "not connected", recorded as Failed, nothing sent. Each bureau sends only its own token: never fall back to SUREPASS_CIBIL_TOKEN for Experian. Internal names (CibilReport, cibil.* permissions, CIBIL Management) stay. Covered by tests/Feature/CibilBureauChoiceTest.php.

## Uncertain is not Failed — never retry it automatically
SurepassCibilClient raises CibilApiException(outcomeUncertain: true) for a timeout after sending, HTTP 500/502/504 and an unreadable/invalid 2xx (the provider may have billed). Such rows block new requests for that PAN+type until the Admin marks them verified (resolveUncertain) or a cibil.refresh holder sends one with after_uncertain + reason. Do not add ->retry() to the HTTP client. 503 and never-opened connections (cURL 6/7/35/…) are plain Failed.

## Nothing sensitive in logs, Telescope or Debugbar
Provider calls and PDF-link downloads run inside CibilHttp::quietly() (Telescope::withoutRecording + Debugbar disabled). Never ->throw() a Surepass response (RequestException messages embed the body), never report() the raw exception (logUnexpected() scrubs via CibilMask), and keep provider messages through CibilMask::scrub before storing. The signed PDF link is never stored in response_payload; pdf_link is encrypted, hidden and kept only while a retry is worthwhile. PDFs live on a private disk (CibilPdfStore::disk() refuses "public") and are served only by CibilReportPdfController (+ its /demo route copy). Tests must use Http::fake + Http::preventStrayRequests — tests/Feature/CibilSurepassClientTest.php, CibilPdfTest.php.

## Visibility follows the files' own listings
CibilReportService::scopeVisible(): Admin all; otherwise requested_by me OR linked to a customer/lead/assigned lead visible through CustomerResource / LeadResource / AssignedLeadResource::getEloquentQuery() (CibilSubject::visibleIds). Those scopes read auth(), so the file reach only applies when $user is the signed-in user. The finer permissions (cibil.full_report, cibil.pdf, cibil.refresh, cibil.audit) only count together with cibil.fetch; Settings/Access pages stay Admin-only.
