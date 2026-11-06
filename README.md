# HishabKhata API

Laravel 12 + Passport 13 + Socialite + PostgreSQL. Backend for [HishabKhata Web](https://github.com/sahimsakir/hishab-khata-web).

## Features

Public email/password and Google registration; user-owned private workspaces; income, expenses, accounts, transfers, recurring bills, personal/bank/car/home/business/credit-card EMI loans, credit cards, DPS/FDR/SIP savings, monthly budgets, daily/monthly/yearly reports, cash flow, and net worth.

Authentication uses **Passport access tokens in encrypted HttpOnly cookies**, Laravel CSRF protection, and session-backed Google OAuth state. Tokens are not returned to JavaScript or stored in localStorage. Login cookies expire after 12 hours; expiry requires sign-in again. Logout revokes the current token. No Sanctum or token refresh endpoint is installed.

The backend follows **Form Request → Controller → Service → Repository interface → Eloquent repository → database**. All record access is scoped to the authenticated user. Balances are calculated on the server; payments are atomic, per-user serialized, and idempotent.

## Quick start with Docker

Requires Docker Compose. Clone this repository, then:

```bash
cp .env.example .env
docker compose build api
docker compose up -d postgres
docker compose run --rm api composer install
docker compose run --rm api php artisan key:generate
docker compose run --rm api php artisan migrate
docker compose run --rm api php artisan passport:keys
docker compose run --rm api php artisan passport:client --personal --provider=users --name="HishabKhata Browser"
docker compose up -d api
```

API: `http://localhost:8000`. PostgreSQL from host tools: `localhost:5433`, database/user `hishabkhata`, password `hishabkhata_local`. Inside Docker, the API uses `postgres:5432`. These credentials are for local development only. The API image runs as UID 1000.

Passport migrations are included. Do **not** run `install:api` or install Sanctum. Create the personal access client once per database. Do not regenerate APP_KEY or Passport keys on each restart; preserve them and the database volume.

## Native PHP setup

Requires PHP 8.3+ with PDO PostgreSQL, mbstring, OpenSSL, curl, XML/DOM, ctype, fileinfo, tokenizer, zip; Composer 2; PostgreSQL. PDO SQLite is required for tests.

```bash
composer install
cp .env.example .env
```

Create the PostgreSQL database/user, then adjust `.env` to match your database. The example host port is 5433; use your native PostgreSQL port if different.

```bash
php artisan key:generate
php artisan migrate
php artisan passport:keys
php artisan passport:client --personal --provider=users --name="HishabKhata Browser"
php artisan serve --host=127.0.0.1 --port=8000
```

Run the React frontend separately using its repository's instructions. Use `localhost` consistently rather than mixing it with `127.0.0.1`. The database starts empty: no shared/default user or financial records are seeded.

## Google registration and login

Create a Google OAuth **Web application** client. Set the authorized redirect URI exactly to:

```text
http://localhost:8000/api/auth/google/callback
```

Configure only in this backend's `.env`:

```dotenv
GOOGLE_CLIENT_ID=your-client-id
GOOGLE_CLIENT_SECRET=your-client-secret
GOOGLE_REDIRECT_URI=http://localhost:8000/api/auth/google/callback
FRONTEND_URL=http://localhost:5173
```

Run `php artisan optimize:clear` (or `docker compose exec api php artisan optimize:clear`). During Google testing mode, configure test users; to allow public Google registration, configure an external audience and publish the OAuth application as Google requires. Credentials are never committed or placed in the frontend.

New verified Google identities create users. An email matching an existing password account is not silently linked: sign in first, then connect Google from your profile with the same email. Socialite validates OAuth state; this flow intentionally does not use `stateless()`. We do not persist Google access/refresh tokens or request Drive/Calendar access.

## Money rules

- Write amounts as decimal strings such as `"0.10"`. Storage/calculations use integer paisa; returned `*_paisa: 10` means ৳0.10. No floating-point arithmetic is used to parse/store/add money.
- Opening balances are pre-existing assets/debts, not income. Use `loan_received` separately for an actual cash disbursement.
- EMI principal comes from the lender's actual breakdown; payment minus principal is interest. The rate is a reference, not an amortization engine.
- Card purchases are expenses once. Repayments reduce cash and debt without adding another expense. Record card fees/interest as card expenses. Update the active statement from your bank each cycle; historical repayments remain, but there is no statement archive or bank reconciliation.
- Credit-card EMI loans can be tracked as loans; keep their opening principal separate from ordinary card opening debt to avoid duplication.
- Recurring plans do not change cash until recorded as paid/received. Partial payments and overdue carry-forward are supported. Loan schedules end at their term; savings schedules stop at maturity. Short months clamp due days to their last day.
- Savings values are recorded principal/contributions. Record actual returns as income and, if reinvested, an equal contribution. No market/NAV feeds, tax calculation, projected interest, or automatic maturity payouts.
- Account/loan/card/savings pages show current totals. Reports show balances through the selected period. Opening balances apply from the beginning of tracked history. The planner, income expectations, and budgets use the selected date's month.
- Referenced records cannot be deleted. Ledger edits/deletions cannot overpay loans or leave savings negative.

## Production

Host Laravel on a PHP-capable server with `public/` as the document root. Use HTTPS and a same-site frontend/API arrangement such as `app.example.com` and `api.example.com`:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.example.com
FRONTEND_URL=https://app.example.com
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
SESSION_DOMAIN=null
GOOGLE_REDIRECT_URI=https://api.example.com/api/auth/google/callback
```

Set real database credentials, install dependencies with `composer install --no-dev --optimize-autoloader`, migrate, provision keys/client once, and run `php artisan config:cache`. Preserve APP_KEY, Passport keys, and database backups. Configure trusted proxy addresses for your TLS setup. Do not expose `artisan serve` or the development PostgreSQL port as production services. SameSite=Lax is not intended for frontend/API on unrelated cross-site domains.

All `/api` routes deliberately retain Laravel's `web` middleware for cookie encryption, sessions, and CSRF validation. The cookie adapter runs before Passport authentication. Do not move API writes into a CSRF-exempt stateless group.

## Verification

```bash
php vendor/bin/phpunit
```

Docker: `docker compose exec api php vendor/bin/phpunit`.

The 20 backend tests passed with 46 assertions on PHP 8.4 / SQLite. See [API.md](API.md) and [VALIDATION.md](VALIDATION.md). PostgreSQL-specific execution, Docker image build, live Google consent, and production deployment were not exercised during source preparation.

No bank integration, actual payment execution, email/SMS reminders, password-reset/email-verification workflow for password accounts, prototype import, or multi-currency support is included. The original static prototype's browser-local data is not imported automatically.
