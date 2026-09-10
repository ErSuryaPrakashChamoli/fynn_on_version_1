---
paths:
  - app/Http/Middleware/EnsureAccountIsActive.php
  - app/Http/Middleware/EnforceIdleTimeout.php
---

# Middleware

## A switched-off account is refused by canAccessPanel; the middleware only makes the exit graceful
User::isDeactivated() = users.is_active explicitly false, OR employees.exit_status === 'yes'. It is checked first in canAccessPanel(), ahead of the portal branches and without changing them, so it also covers Livewire round-trips (which carry Filament's persistent Authenticate but NOT the panel's authMiddleware).

`? true` on is_active matters: a User row created in the same request has no is_active loaded (the default lives in the DB), and a null must never read as "switched off".

EnsureAccountIsActive is registered in the panel's ->middleware() list right after StartSession, NOT in ->authMiddleware(). Laravel's middleware priority hoists Filament's Authenticate above anything in authMiddleware, and Authenticate answers a refused canAccessPanel() with a bare 403; running before it turns that into a sign-out with a reason, and closes the login-session row as REASON_ACCOUNT_DEACTIVATED.

App\Filament\Pages\Auth\Login::throwFailureValidationException() replaces Filament's generic "credentials do not match" with a plain "your account is no longer active" — otherwise the only symptom of an approved exit is a password that appears to have stopped working.

tests/Feature/DeactivatedAccountAccessTest.php covers login, an open session, and a user with no employee row.

## A closed login-session row must sign the browser out — otherwise the heartbeat reload-loops
Closing a user_login_sessions row does NOT end the browser's Laravel auth session. Two things close rows behind a live browser: `sessions:close-idle` and StartLoginSession, which closes every open row for the user on any new login (logout_reason 'new_login'), including on other devices.

EnforceIdleTimeout therefore acts on `logout_at !== null`, not just on isIdle(). Before that it returned $next() for a closed row, so the browser kept a working panel while login-session-heartbeat.js got 404 forever and called window.location.reload() on every beat — a page refresh roughly every 10 seconds that never reached the login page.

Two invariants to keep:
- Never put EnforceIdleTimeout on the /login-session/heartbeat route. It stamps last_activity_at on everything that passes through, so a beating-but-untouched tab could never go idle. The heartbeat reports; the middleware is the authority; the script's single guarded reload bridges them.
- A missing login_session_id (browser predates the feature) and a missing row (log pruned) are NOT evidence the session ended — leave both alone.

Covered by tests/Feature/IdleSessionLogoutTest.php.
