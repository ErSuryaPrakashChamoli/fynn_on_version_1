---
paths:
  - 'app/Support/SelectedMonth.php, resources/views/filament/components/global-month-selector.blade.php, app/Http/Middleware/EncryptCookies.php'
---

# Http Middleware

## Topbar period selector: month, financial year (Apr–Mar) or all time; range() follows the mode, current() stays one month
Since 2026-09-27 the topbar picks a period, not only a month: cookies `selected_month` ("Y-m") and `selected_period` ("month" default | "fy:2026" = April 2026–March 2027 | "all" = 2000-01-01 → now+5y), both unencrypted (EncryptCookies $except). SelectedMonth::range()/label()/isCurrentCalendarMonth()/elapsedDays()/remainingDays() follow the mode, so every listing filter and widget that scopes by range() covers the FY or all time automatically. SelectedMonth::current() ALWAYS returns one calendar month for monthly-by-nature callers (targets, incentives, calendars, AchievementCalculatorService): the selected month, or for FY/all the month today falls in (last month of a past period, first of a future one) — do not make it return a range. isCurrentCalendarMonth() now means "today is inside the selected period". Widgets must use elapsedDays()/remainingDays() rather than daysInMonth arithmetic (CustomerStats, PerformanceStats were changed). In tests park the selector with request()->cookies->set(...) — $this->call() does not leave its request bound. Covered by tests/Feature/SelectedPeriodTest.php.
