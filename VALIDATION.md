# Validation

Executed on 6 November 2026:

- Laravel PHPUnit: **20 tests passed, 46 assertions**, PHP 8.4.27 with SQLite in-memory test databases.
- React TypeScript compilation and Vite production bundle: passed.
- Frontend Vitest: **4 tests passed**.
- Composer dependencies installed and locked; npm dependencies installed and locked.

Backend checks cover Passport's real cookie issue/authenticate/revoke lifecycle, cookie flags, rejection of client bearer headers without the cookie, CSRF rejection, password login failures, Google verified identity registration and safe linking, cross-user read/write isolation, exact money parsing, loan principal overpayment rejection, partial EMI carry-forward, short-month due dates, card purchase/repayment separation, transfers, negative savings prevention, and request retry/conflict handling.

Google identity tests use mocked provider user objects, not a live Google Cloud client. Live Google consent/callback cannot be confirmed until you configure your client ID, secret, and exact redirect URI. Docker is supplied but was not built in this environment; PostgreSQL-specific execution was not run here. The Laravel financial rules and migrations were exercised using SQLite. No browser-level React journey or production deployment was performed for this package.

Runtime dependencies, node_modules, vendor, generated app/Passport secrets, logs, caches, local databases, and generated build directories are intentionally excluded from the source archive. Install reproducibly using the included lockfiles.
