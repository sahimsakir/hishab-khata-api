# API reference

All endpoints use `/api`. JSON responses, `Accept: application/json`. Authenticated requests require the encrypted `hishabkhata_access` cookie. Write requests additionally require the session and `X-CSRF-TOKEN`. Always use `credentials: include`.

| Method | Path | Purpose |
|---|---|---|
| GET | /auth/csrf | Establish session and return csrf_token |
| POST | /auth/register | name, email, password, password_confirmation |
| POST | /auth/login | email, password |
| GET | /auth/google/redirect | Browser navigation to Google sign-in |
| GET | /auth/google/callback | Socialite validates state, creates/signs in user, sets cookie, redirects to SPA |
| GET | /auth/me | Current user |
| POST | /auth/logout | Revoke current token and remove cookie |
| GET | /auth/google/link | Authenticated browser navigation to deliberately connect Google |
| GET | /finance?date=2026-11-06&period=Monthly | Owned records/transactions, current balances, planner, period summary |
| POST | /records | Create typed configuration record |
| PUT | /records/{uuid} | Replace record fields |
| DELETE | /records/{uuid} | Delete unused record |
| POST | /transactions | Record money movement; use a stable idempotency_key for retries |
| PUT | /transactions/{uuid} | Replace a transaction; its request key is retained |
| DELETE | /transactions/{uuid} | Delete transaction if dependent balances remain valid |

Statuses: 401 unauthenticated/expired cookie, 404 absent or another user's record, 419 CSRF mismatch, 422 validation/business-rule failure, 429 throttled. No user_id is accepted from the caller; the authenticated user's ID is always used.

## Record example

```json
{
  "kind": "accounts",
  "name": "My bank",
  "data": { "type": "Bank", "opening": "5000.10" }
}
```

Allowed kinds: `accounts`, `incomes`, `loans`, `cards`, `savings`, `bills`, `budgets`. Field definitions are in `the frontend repository’s `src/forms.ts`` and authoritative validation is in `app/Services/RecordService.php`. Returned monetary fields have `_paisa` suffixes and integer values: opening `"5000.10"` is returned as `opening_paisa: 500010`.

## Expense example

Replace the illustrative UUID with an account UUID returned by your own workspace:

```json
{
  "type": "expense",
  "name": "Small purchase",
  "date": "2026-11-06",
  "amount": "0.10",
  "category": "Other",
  "account_id": "uuid-from-your-workspace",
  "idempotency_key": "new-uuid-per-user-action"
}
```

Transaction types: `income`, `expense`, `saving`, `savings_withdrawal`, `loan_payment`, `card_payment`, `transfer`, `loan_received`.

- `transfer`: destination_id, a different owned money account.
- `loan_payment`: loan_id and principal as a decimal string. `interest_paisa` is calculated server-side as amount minus principal.
- `saving` / `savings_withdrawal`: saving_id.
- `card_payment`: card_id; statement_date defaults to the card's active statement date.
- For a scheduled receipt/payment: source_id and cycle (`YYYY-MM`). The source must match the transaction's type/loan/savings plan, and the payment cannot exceed its scheduled remainder.
- Credit cards can be account_id only on `expense` (card purchase), not as the cash source for transfers/EMIs/savings.

## Storage

Finance configuration uses typed `finance_records` with a validated JSON `data` column. Transactions are in a relational ledger with foreign keys to those records and dedicated integer-paisa amount/principal/interest columns. Repositories scope all reads and writes by user. Foreign keys restrict deletion of referenced records. This avoids one large mutable JSON workspace document while keeping plan-specific metadata flexible.
