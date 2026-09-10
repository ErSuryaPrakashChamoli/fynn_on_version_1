---
paths:
  - app/Http/Middleware/EnsureAccountIsActive.php
---

# Middleware

## A switched-off account is refused by canAccessPanel; the middleware only makes the exit graceful
User::isDeactivated() = users.is_active explicitly false, OR employees.exit_status === 'yes'. It is checked first in canAccessPanel(), ahead of the portal branches and without changing them, so it also covers Livewire round-trips (which carry Filament's persistent Authenticate but NOT the panel's authMiddleware).

`? true` on is_active matters: a User row created in the same request has no is_active loaded (the default lives in the DB), and a null must never read as "switched off".

EnsureAccountIsActive is registered in the panel's ->middleware() list right after StartSession, NOT in ->authMiddleware(). Laravel's middleware priority hoists Filament's Authenticate above anything in authMiddleware, and Authenticate answers a refused canAccessPanel() with a bare 403; running before it turns that into a sign-out with a reason, and closes the login-session row as REASON_ACCOUNT_DEACTIVATED.

App\Filament\Pages\Auth\Login::throwFailureValidationException() replaces Filament's generic "credentials do not match" with a plain "your account is no longer active" — otherwise the only symptom of an approved exit is a password that appears to have stopped working.

tests/Feature/DeactivatedAccountAccessTest.php covers login, an open session, and a user with no employee row.
