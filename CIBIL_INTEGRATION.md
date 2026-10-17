# CIBIL (Surepass) integration

How FynnOn checks a customer's or lead's credit score and credit report through
Surepass, from **CIBIL** or **Experian**. The module is internal to the LMS: only
signed-in staff with the CIBIL permissions can use it.

## Choosing the bureau

The bureau is the first choice on every new request: the first section of the
**Fetch bureau report** form, and the first field of each request action on a lead
or customer file's credit profile. *Refresh report* on a report page asks that
report's own bureau again.

- Both bureaus are always offered, with every request type. The choice does not
  depend on whether a bureau's API is set up. The master switch and the per-type
  switches in **CIBIL Settings** apply to both.
- A bureau or request type whose token or endpoint is not set yet fails when sent,
  recorded as *Failed* with "The Experian API is not connected yet". Nothing is sent
  or billed.
- Reports from one bureau never stand in for the other's. Reuse, the in-flight lock
  and the uncertain-outcome block all work per PAN + **bureau** + request type.
- Each bureau uses only its own token. The CIBIL token is never sent to Experian.
- Rows from before this change are CIBIL rows.

## Architecture

```
Filament (CIBIL profile on a lead/customer, fetch form, report page)
        │  direct service call, no internal HTTP
        ▼
App\Services\Cibil\CibilReportService ── request()
   1. permission for this request type        (App\Support\CibilAccess)
   2. Admin settings allow it                 (App\Models\CibilSetting)
   3. details + consent valid, PAN = file's PAN
   4. under a cache lock per PAN + bureau + type:
        stale "pending" → "uncertain"
        refuse if one is pending / uncertain and unverified
        reuse the file's existing report if it already answers
        else insert a "pending" row
   5. call the chosen bureau ────────► App\Services\Cibil\CibilClient
   6. store result / failure + audit         ├─ SurepassCibilClient (live, billable)
                                              ├─ FakeCibilClient (dev, /demo)
                                              └─ UnconfiguredCibilClient (default)
   PDF → App\Services\Cibil\CibilPdfStore → private disk → /cibil-reports/{id}/pdf
```

Each request is one row in `cibil_reports`. Rows are never edited after the
answer arrives. The only later writes are bookkeeping: the bureau decision on a
lead, the customer link added when a lead converts, PDF saving, and the
verification of an uncertain request.

## Surepass endpoints

Base URL: `https://kyc-api.surepass.app`, authenticated with `Authorization: Bearer <token>`.

| Request type | Endpoint | Stored |
| --- | --- | --- |
| Score check | `POST /api/v1/credit-report-cibil/score` | score, `client_id` |
| Full report | `POST /api/v1/credit-report-cibil/fetch-report` | score, `client_id`, complete `credit_report` (encrypted) |
| PDF report | `POST /api/v1/credit-report-cibil/fetch-report-pdf` | score, `client_id`, PDF on the private disk |

**Experian** (`SUREPASS_EXPERIAN_*`), from Surepass's Experian API docs (confirmed
2026-10-17). The env overrides are only needed if Surepass changes a path.

| Request type | Endpoint | Env override | Stored |
| --- | --- | --- | --- |
| Score check | `POST /api/v1/credit-report-experian/score` | `SUREPASS_EXPERIAN_SCORE_ENDPOINT` | score, `client_id` |
| Full report | `POST /api/v1/credit-report-experian/fetch-report` | `SUREPASS_EXPERIAN_REPORT_ENDPOINT` | score, `client_id`, the `credit_report` object (`CreditProfileHeader`, `CAIS_Account`, …) encrypted |
| PDF report | `POST /api/v1/credit-report-experian/fetch-report-pdf` | `SUREPASS_EXPERIAN_PDF_ENDPOINT` | score, `client_id`, the PDF from `credit_report_link` (signed S3 link) on the private disk |

Request body:

- **CIBIL:** `pan`, `mobile`, `name`, `gender` and `consent: "Y"`.
- **Experian:** `pan`, `mobile`, `name` and `consent: "Y"`. Experian does not take
  gender, so the form asks for gender only when CIBIL is chosen.

`consent` is only sent after consent has been recorded (see below).

## Environment variables

| Variable | Default | Meaning |
| --- | --- | --- |
| `CIBIL_DRIVER` | `unconfigured` | `surepass` = live; `fake` = dev stand-in (ignored in production); `unconfigured` = every request fails with "not connected" |
| `SUREPASS_CIBIL_BASE_URL` | `https://kyc-api.surepass.app` | Must be `https://` |
| `SUREPASS_CIBIL_TOKEN` | — | Server environment only. Never committed, shown, stored on a report or logged. No other bureau's token is ever used in its place. |
| `SUREPASS_CIBIL_TIMEOUT` / `SUREPASS_CIBIL_CONNECT_TIMEOUT` | 60 / 10 s | Keep PHP-FPM's `max_execution_time` above the timeout |
| `SUREPASS_EXPERIAN_TOKEN` | — | Experian's own token. Same rules as the CIBIL token. Without it every Experian request fails with "Experian credentials are not configured". |
| `SUREPASS_EXPERIAN_BASE_URL` | the CIBIL base URL | Must be `https://` |
| `SUREPASS_EXPERIAN_SCORE_ENDPOINT` / `_REPORT_ENDPOINT` / `_PDF_ENDPOINT` | see above | Leave unset; only for a changed Surepass path |
| `SUREPASS_EXPERIAN_TIMEOUT` / `SUREPASS_EXPERIAN_CONNECT_TIMEOUT` | the CIBIL values | |
| `CIBIL_PDF_DISK` | `local` | Private disk. A public disk is refused and `local` is used instead. |
| `CIBIL_PDF_MAX_BYTES` | 15 MB | Larger files are rejected |
| `CIBIL_PENDING_STALE_MINUTES` | 5 | A request still pending after this is marked "outcome uncertain" |

The `/demo` environment always uses the fake bureau.

## Where staff use it

- **CIBIL Credit Profile**: a section on the **customer view page**, the **My Leads lead
  page** (its edit page, which is the lead's detail page) and the **assigned lead view
  page**. It shows the name, masked PAN and mobile, latest score, last successful report,
  latest request status, PDF status and the full request history. Its actions are
  *Check credit score*, *Fetch full report*, *Get PDF report* and *Refresh bureau report*
  (each asks for the bureau first),
  each pre-filled from the file. A PAN already on the file is locked. Gender and any
  missing details are typed into the form. Opening a file never contacts the bureau.
- **CIBIL Management** sidebar group:
  - **CIBIL Dashboard**: real counts by type and outcome, recent requests, recent failures.
  - **CIBIL Reports**: one row per applicant, with the report page, history, PDF,
    *Refresh report*, and the lead's Eligible / Not Eligible decision.
  - **CIBIL Requests**: every request with its timings, provider HTTP status and
    sanitized error. **Failed Requests** is the same list on its *Failed & uncertain* tab.
  - **CIBIL Settings** (Admin): master switch, per-type switches, reuse window and
    fresh pulls, plus each bureau's configuration status. Tokens are never shown.
  - **CIBIL Access** (Admin): grants each permission to roles and/or individual users.
  - **Audit History**: the `cibil` activity log.

## Permissions

| Permission | Allows |
| --- | --- |
| `cibil.fetch` | Module access: the CIBIL profile on files the user can already see, and score checks |
| `cibil.full_report` | Request and read full reports |
| `cibil.pdf` | Request and open PDFs |
| `cibil.refresh` | Fresh pulls over a reusable report, and a new request after an uncertain one |
| `cibil.audit` | Audit history and provider references (`client_id`, response codes) |

- The Admin has every permission. The finer permissions only count together with
  `cibil.fetch`.
- Settings and the Access page are Admin-only.
- Every action is checked on the server (`CibilReportService`, the PDF controller and
  each page's `canAccess`), not just hidden in the UI.
- **Visibility:** the Admin sees every report. Anyone else sees the reports they
  requested, plus reports on leads and customer files they can already see through
  those listings' own scope (hierarchy, delegation, takeover). So a manager finds the
  report a caller pulled on a team file instead of paying for another one.
- When the migration runs, existing `cibil.fetch` grants are copied to
  `cibil.full_report`, so current users keep what they had.

## Consent

Every request records:

- `consent_method`: recorded call, signed form, digital or email
- an optional `consent_reference` (call recording ID, form number, …)
- `consent_purpose`, the stated purpose for that request type
- the time (`consent_given_at`) and the employee (`requested_by`)

The tick box alone is not accepted. Consent is per enquiry: every new (billable)
request needs a fresh attestation, while reusing a report needs none. Everything is
also written to the activity log.

## Report reuse and billable-request safety

- **Reuse is per file and never by PAN alone.** A request for a lead or customer file is
  answered from that file's own successful report when it covers the request:
  - a score is covered by a score, full report or PDF;
  - a full report only by a full report;
  - a PDF only by a saved PDF.
- **Reuse window:** *Reuse a report for (days)* in CIBIL Settings. Empty (the default)
  means a file's report is reused until someone explicitly asks for a fresh pull. No
  universal expiry is assumed.
- **Fresh pulls** need `cibil.refresh` and a reason. They can be switched off in Settings.
- **Duplicates:** a cache lock per PAN + type covers the check-and-record step. A pending
  row for the same PAN + type then refuses further requests ("A previous request is
  still processing").
- **Uncertain outcomes:** a timeout after sending, HTTP 500/502/504, an unreadable 2xx,
  or a crash mid-call is never retried automatically. It blocks new requests for that
  PAN + type until it is verified, either by the Admin's *Mark as verified* or by a
  `cibil.refresh` holder who ticks the acknowledgement and gives a reason.
- A failed request never touches earlier successful reports. Every request records
  why it was sent (`request_reason`).

## Error handling

| Provider answer | Stored as | Staff message |
| --- | --- | --- |
| Token missing | Failed (nothing sent) | CIBIL credentials are not configured |
| 400 / other 4xx, `success:false` | Failed | The provider rejected the request (+ provider message, scrubbed of PAN, mobile and links) |
| 401 | Failed | The provider did not accept the CIBIL credentials |
| 403 | Failed | The provider account is not permitted |
| 429 | Failed | The provider is limiting requests, please wait |
| 503, connection never opened | Failed | The CIBIL service is temporarily unavailable |
| 500 / 502 / 504, timeout after sending, malformed 2xx | **Uncertain** | The request outcome is uncertain; verify its status before retrying |
| Missing score | Fetched, score empty | "No score returned" |
| Missing `client_id` | Fetched, no provider reference | — |
| Empty or missing `credit_report` | Failed | The provider returned no credit report |
| No PDF link or file | Failed | The provider did not return a PDF report |

Settings and in-flight refusals are shown without a row being created and without
anything being billed. Logs never contain the token, the request body, the report, a
full PAN or mobile number, or a signed link. Provider calls are also kept out of
Telescope and Debugbar.

## PDF access

- The provider's file (inline base64, or the signed link fetched once, immediately) must
  start with `%PDF-` and stay within the size limit. It is saved to
  `storage/app/private/cibil-reports/{report}/…pdf`.
- The signed link is never shown or logged. It is kept (encrypted) only if the download
  failed for a reason worth retrying. *Retry PDF download* uses it without a new bureau
  request.
- A link that answers 401/403/404/410 is recorded as **expired**, and a new PDF request
  is needed.
- PDFs are opened only through `GET /cibil-reports/{id}/pdf` (and `/demo/…`). The route
  checks login, report visibility (otherwise 404) and `cibil.pdf` (otherwise 403),
  sends `no-store`, and every opening is audited.

## Lead to customer conversion

`CreateCustomer::afterCreate()` calls `CibilReportService::linkConvertedLead()`. The
converted lead's (or assigned lead's) reports for the PAN the customer was created with
gain the `customer_id`. The same rows are used and nothing is copied, so the customer
profile shows the lead's history and reuses it.

## Queue and scheduler

Nothing needs to be added. Requests run synchronously from the Filament action so the
employee sees the result immediately. Stale pending requests are settled when a request
is made, and when the CIBIL Dashboard or CIBIL Requests page is opened.

## Production activation (only after approval)

```bash
git pull                                   # the reviewed branch
composer install --no-dev --optimize-autoloader
php artisan migrate --force                # cibil_reports columns + permissions, cibil_settings, bureau column
php artisan demo:migrate                   # if /demo is deployed
# in the server's .env (never committed):
#   CIBIL_DRIVER=surepass
#   SUREPASS_CIBIL_BASE_URL=https://kyc-api.surepass.app
#   SUREPASS_CIBIL_TOKEN=<token from Surepass>
#   SUREPASS_EXPERIAN_TOKEN=<Experian token from Surepass>   # only when Experian is wanted
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan filament:optimize
```

Then, as the Admin:

1. **CIBIL Settings**: check that the token shows *Configured* and the driver *Live Surepass*.
2. **CIBIL Access**: grant `cibil.fetch` and the finer permissions.
3. **CIBIL Settings**: switch requests on.

Requests stay off until step 3. Until the Experian token is set, Experian requests
fail with "Experian credentials are not configured" without anything being sent.
