# Mini Payment Gateway: Mentor Defense Guide

This document is a speaking guide for defending the application from top to bottom.
It describes the actual implementation, the request flow, the important terminology,
what to demonstrate, and the limitations to acknowledge.

Do not show `.env`, provider secrets, API secrets, passwords, or the raw Laravel log
during a presentation. Use test credentials and redact request data in screenshots.

## 1. Thirty-second project explanation

> I built a sandbox payment gateway with a Laravel 13 backend, PostgreSQL, a Next.js
> merchant dashboard, Sanctum browser authentication, scoped merchant API keys,
> provider adapters, signed webhooks, database queues, wallet and ledger records,
> and hosted payment links. The difficult engineering problems are tenant
> isolation, authentication and authorization, idempotency, concurrent wallet
> updates, asynchronous provider state, webhook replay protection, and safe
> failure handling. It is an educational prototype, not a licensed real-money
> processor.

The system has two clients:

1. A browser user operating the merchant dashboard.
2. A separate business application integrating through `/api/v1` with an API key.

The browser session and the B2B API key are intentionally separate authentication
mechanisms.

## 2. Technology and responsibilities

### Backend

- Laravel 13 provides routing, middleware, validation, sessions, notifications,
  queues, Eloquent ORM, database transactions, and exception handling.
- PostgreSQL stores merchants, users, API keys, transactions, wallets, ledger
  entries, withdrawals, payment links, webhook deliveries, sessions, and audit data.
- Laravel Sanctum authenticates the browser UI.
- Eloquent models represent domain records and relationships.
- Form Request classes validate complex input before controllers perform business work.
- Enums represent payment-link, payment, withdrawal, ledger direction, and ledger
  entry types.
- Provider adapters isolate Paystack/Cray-specific HTTP behavior behind a common
  provider contract.
- A database queue runs asynchronous jobs such as merchant webhook delivery.

### Frontend

- Next.js App Router maps folders under `src/app` to URLs.
- React components render forms, tables, loading states, errors, dialogs, and
  dashboard navigation.
- Axios in `src/lib/api.ts` centralizes the API base URL, credentials, CSRF header,
  and one-time 401 redirect behavior.
- `AuthContext` loads the authenticated principal and exposes login/logout state.
- Dashboard providers and role guards protect the merchant UI.
- The frontend polls only while records are pending or processing, rather than
  continuously refetching every page.

## 3. The first demonstration: show the route map

Open the frontend and show these routes:

- `/login` - merchant/team login
- `/register` - merchant registration
- `/verify-device` - new-device challenge
- `/verify-otp` - OTP verification
- `/` - dashboard overview
- `/transactions` - payment list and verification
- `/payment-links` - hosted checkout links
- `/wallets` - wallet balance and ledger access
- `/withdrawals` - payout list
- `/withdrawals/create` - payout form
- `/team` - team-member administration
- `/api-keys` - API-key lifecycle
- `/webhooks` - webhook settings and delivery history
- `/settings` - merchant profile and settings
- `/docs` - API integration documentation
- `/pay/[token]` - public hosted checkout
- `/admin/login` and `/admin/*` - separate internal admin surface

Explain that route visibility is not authorization. A user can type any URL, so
the backend repeats authentication, role, merchant, and scope checks.

## 4. Browser authentication from request to session

### Registration

The registration request is validated by `RegisterMerchantRequest`. The backend
creates the merchant and its related wallet/settings records, hashes the password
with Laravel's password hashing mechanism, and sends an email verification OTP.
The password is never stored in plaintext.

The verification flow is:

1. The user submits registration data.
2. The backend creates an unverified merchant.
3. An OTP is generated and sent through a notification.
4. The user submits the OTP to `/api/auth/email/verify-otp`.
5. The backend checks expiry, account identity, and one-time usage.
6. The merchant becomes verified.

The verification endpoints are throttled to reduce guessing and resend abuse.

### Login

The login request reaches `/api/auth/login` and is throttled. The backend:

1. Validates the email and password.
2. Locates the merchant or team member.
3. Checks email verification and active/suspended status.
4. Identifies the login context, including device information.
5. Looks up a known device belonging to that exact merchant principal.
6. If the device is unknown, creates a short-lived device challenge.
7. Sends an OTP and does not create the authenticated session yet.
8. If the device is known, continues to session creation.

A device challenge contains an opaque random value, an expiry, a one-time-use
condition, and the login context. It is not a password and does not directly
authenticate an unrelated account.

### Device OTP

For a new device:

1. The frontend navigates to `/verify-device?challenge=...`.
2. The user submits the OTP to `/api/auth/device/verify`.
3. The backend resolves the challenge and checks that it is unexpired.
4. It checks that the OTP is correct and has not already been consumed.
5. It reloads the merchant/team principal and checks that the account is still active.
6. It records the device for the correct merchant/principal.
7. It logs the user in and rotates the session identifier.
8. The challenge is consumed so it cannot be reused.

Important terms:

- **Session fixation**: an attacker tries to make a victim use a session identifier
  known to the attacker. Session rotation after authentication prevents this.
- **Replay attack**: reusing an old challenge, OTP, or authenticated request.
  Expiry, one-time consumption, and logout invalidation address this.
- **Principal binding**: a known device for Merchant A must not authenticate Merchant B.

### Logout and sessions

`/api/auth/logout` invalidates the current browser session. The session-management
endpoints list sessions, revoke an individual session, or revoke other sessions.
A replayed request using a revoked session must receive `401 Unauthenticated`.

What to demonstrate:

1. Log in from an unknown browser/device context.
2. Show that OTP is required.
3. Submit a wrong OTP and show rejection.
4. Submit an expired or reused OTP and show rejection.
5. Complete OTP and show the dashboard.
6. Log out.
7. Repeat a previously authenticated request and show `401`.
8. Revoke another session and show that it no longer works.

## 5. Team members and role-based access control

The team model is `MerchantUser`. Each team member has:

- `merchant_id`
- name and email
- hashed password
- role
- active flag
- email verification state

The supported operational roles are:

- `owner`: the primary merchant account
- `admin`: merchant operations and team management
- `developer`: API keys and technical integration
- `finance`: wallet ledger and withdrawal operations

### Invitation flow

An administrator submits name, email, and role to `POST /api/team`.
The backend:

1. Validates the role against `admin,developer,finance`.
2. Derives the merchant from the authenticated principal.
3. Rejects an email already used by a merchant, admin, or team member.
4. Creates the team member with a random temporary password.
5. Sets `email_verified_at` to null.
6. Generates a random invitation token.
7. Stores the token in cache for 24 hours.
8. Sends the invitation notification.

The invited person accepts through `/api/auth/team/accept-invite`, chooses a
password, and verifies the invitation email/token.

### Backend role enforcement

`CheckRole` runs after Sanctum authentication. It:

1. Rejects unauthenticated requests with `401`.
2. Allows merchant owners to pass owner-authorized merchant routes.
3. Checks a team member's stored role against the route's allowed roles.
4. Returns `403` when the authenticated user lacks permission.

The route groups in `routes/api.php` are:

- Everyone: dashboard reads, transactions, payment links, basic settings/wallet
  reads.
- `admin,developer`: API keys and webhook configuration/delivery tools.
- `admin,finance`: ledger reads and withdrawals.
- `admin`: team management and sensitive merchant settings.

The frontend may hide buttons, but that is only UX. The server-side middleware is
the real authorization boundary.

### Team-management safety rules

The backend prevents:

- Modifying or deleting the owner.
- A user modifying their own team account through this endpoint.
- A team member modifying/deleting an admin account.
- Accessing a team member from another merchant.
- Arbitrary role values.

Deactivation sets `is_active=false` and deletes the user's Sanctum tokens.
Future authentication also checks the active flag.

## 6. API keys: creation, storage, scopes, expiry, and rotation

The API-key lifecycle is implemented in `ApiKeyController`, `ApiKeyService`,
`ApiKey`, and `ApiKeyResource`.

### Creation

`POST /api/api-keys` requires an authorized merchant UI principal and validates:

- name: required string, maximum 255 characters
- environment: `test` or `live`
- scopes: at least one allowed scope
- expiration_days: optional integer from 1 through 365

Supported scopes are:

- `payments:read`
- `payments:write`
- `withdrawals:read`
- `withdrawals:write`
- `wallets:read`
- `*`

The service generates a high-entropy secret and stores its protected/hash form.
The plaintext secret is attached only to the creation/rotation response. Normal
list responses expose metadata, not the reusable secret.

### API-key request flow

For a B2B request:

1. The client sends `Authorization: Bearer <key>`.
2. `VerifyApiKey` reads and trims the bearer token.
3. It hashes the presented secret with SHA-256.
4. It finds the matching key and eager-loads its merchant.
5. It rejects missing, invalid, revoked, or expired keys with `401`.
6. It updates `last_used_at`.
7. It attaches the key's merchant and scopes to the request.
8. `CheckApiScope` checks the required permission.
9. The controller receives a merchant context derived from the key.

Authentication answers "which merchant owns this key?" Authorization answers
"does this key have permission for this operation?" They are different checks.

### Scope examples

- `GET /api/v1/payments` requires `payments:read`.
- `POST /api/v1/payments` requires `payments:write`.
- `GET /api/v1/withdrawals` requires `withdrawals:read`.
- `POST /api/v1/withdrawals` requires `withdrawals:write`.

The wildcard scope is explicit full access. It is convenient for trusted demos,
but a stricter production policy would limit or remove wildcard key creation.

### Lifetime, expiry, revoke, and rotate

If a lifetime is supplied, the server calculates `expires_at` using server time.
The key is invalid if revoked or if `expires_at` is in the past. If no lifetime
is supplied, the current implementation allows a non-expiring key; operationally,
short-lived keys and regular rotation are safer.

Revocation marks the old key with `revoked_at`. Rotation creates a new secret and
returns the new plaintext value once. A safe rotation sequence is:

1. Rotate/create the new key.
2. Store it in the integrating application's secret manager.
3. Deploy the integrating application.
4. Confirm requests work.
5. Revoke the old key.

## 7. B2B payment integration demonstration

Use Postman or curl as a simulated external business.

Example:

```http
POST http://localhost:8080/api/v1/payments
Authorization: Bearer YOUR_TEST_API_KEY
Idempotency-Key: mentor-demo-order-001
Content-Type: application/json
```

```json
{
  "amount": 5000,
  "currency": "NGN",
  "reference": "mentor-demo-order-001",
  "callback_url": "https://example.test/payment-complete"
}
```

Explain the middleware sequence:

1. `auth.api_key` authenticates the key.
2. `merchant.active` rejects suspended merchants.
3. `scope:payments:write` checks authorization.
4. The payment request validator checks amount, currency, reference, callback URL,
   and metadata.
5. The controller derives merchant ownership from the API key.
6. The provider adapter initializes the provider-side payment.
7. The local transaction is returned with a status and checkout URL/reference.

Then send the exact request again. The same idempotency key and same payload must
reuse the existing result rather than create a duplicate.

Then change the amount while keeping the key. The server must reject the request
because the idempotency fingerprint does not match.

## 8. Idempotency and transaction consistency

Payment clients retry when a network response is lost. A retry without idempotency
could charge or create two transactions.

The client supplies an idempotency key. The backend stores a fingerprint of the
important request values. The behavior is:

- Same key, same fingerprint: return/reuse the existing operation.
- Same key, different fingerprint: reject as a conflict.
- New key: create a new operation.

The key is not a replacement for database uniqueness or transactions; it works
together with them. Database constraints, row locks, and state validation are the
last line of defense against concurrent duplicates.

## 9. Payment provider abstraction

The provider contract defines common operations such as initialization, verification,
withdrawal initiation, and provider response normalization. `ProviderFactory`
selects the provider, while Paystack and Cray adapters contain provider-specific
URLs, headers, signatures, and response shapes.

This is the **adapter pattern**: controllers depend on a stable application
interface instead of duplicating Paystack/Cray details.

The application must not mark a payment successful merely because a provider
initialization request returned HTTP success. Initialization usually means that
checkout was created; verification or a trusted webhook determines final status.

## 10. Hosted payment links and checkout

A merchant creates a payment link through the dashboard. The backend stores the
server-authoritative amount, currency, reference, status, expiry, and merchant.
The public URL contains an opaque token, not an authorization credential for the
merchant dashboard.

The customer opens `/pay/[token]`. The frontend loads the public checkout data
and submits the checkout request to the backend.

The backend:

1. Finds the payment link by token.
2. Locks/revalidates the record where a state transition is possible.
3. Checks that the link is active and not expired.
4. Uses server-stored amount/currency/reference values.
5. Prevents a second successful processing of a terminal link.
6. Validates the callback/redirect target.
7. Starts or verifies the provider operation.

The browser must show pending, failed, cancelled, or successful based on the
backend state. Opening a checkout page is not proof of payment.

## 11. Wallets and the ledger

The wallet is the current balance. The ledger is the auditable history of changes.
Wallet and ledger records must change together inside a database transaction.

For an incoming payment:

1. The provider confirms a valid payment.
2. The wallet row is locked.
3. The system checks that the transaction has not already credited the wallet.
4. The balance is credited.
5. A unique ledger entry is created.
6. The transaction moves to its terminal successful state.
7. The database transaction commits.

For a withdrawal:

1. Authenticate the request.
2. Check merchant activity.
3. Check the UI role or API-key scope.
4. Validate amount, currency, bank code, account number, and provider.
5. Resolve/validate the bank account where applicable.
6. Begin a database transaction.
7. Lock the merchant wallet row with a pessimistic row lock.
8. Check available balance, fees, and minimum/maximum rules.
9. Debit/reserve the wallet.
10. Create a withdrawal with a unique reference and `PENDING` status.
11. Create the corresponding debit ledger entry.
12. Commit the local reservation.
13. Call the selected provider to initiate the payout.
14. Keep the withdrawal pending/processing until provider verification or webhook.
15. On provider failure, mark the withdrawal failed and reverse the debit once.

**Pessimistic locking** means the second concurrent withdrawal waits for the
wallet row lock and then observes the first committed balance. Without the lock,
two requests could both read the same old balance and overspend it.

If the provider call fails after the local debit, the reversal path restores the
wallet and records the failure. Reversal must itself be idempotent so a repeated
failure notification cannot refund the wallet twice.

Payment and payout are opposite money directions:

- Payment: provider/customer money enters the merchant wallet.
- Payout: merchant wallet money leaves toward a bank account.

## 12. Withdrawal authorization in exact terms

### Browser path

The route is under `auth:sanctum` and `merchant.active`, then under
`role:admin,finance`. Therefore:

- A missing session receives `401`.
- A suspended/inactive merchant is rejected.
- A developer without finance/admin role receives `403`.
- A finance or admin user continues to validation and withdrawal logic.

### B2B API path

The route is under `auth.api_key` and `merchant.active`, then requires
`withdrawals:write`. Therefore:

- Missing/invalid/revoked/expired key receives `401`.
- Inactive merchant is rejected.
- A valid key without `withdrawals:write` receives `403`.
- A valid key with the scope proceeds.

This is the complete answer to "what authenticated and scoped the payout?"
Sanctum authenticates browser users; `VerifyApiKey` authenticates B2B clients;
`CheckRole` authorizes browser roles; `CheckApiScope` authorizes API-key scopes;
`EnsureMerchantIsActive` blocks inactive merchants; the controller then performs
merchant-scoped validation and wallet consistency work.

## 13. Webhooks

Webhooks are asynchronous provider-to-backend notifications. The backend is the
source of truth for state changes, not the browser redirect.

For a webhook:

1. The route identifies the provider.
2. The handler reads the raw request body.
3. It calculates/verifies the provider signature using the configured secret.
4. It rejects malformed or unsigned payloads.
5. It identifies the local transaction/withdrawal by provider reference.
6. It validates merchant, amount, currency, fee, and event type.
7. It checks replay identity and the current terminal state.
8. It applies the allowed transition.
9. It credits/reverses the wallet only once.
10. It records the result and returns a safe response.

Signature verification uses the raw body because parsing and reserializing JSON
can change the exact bytes used to calculate a signature.

Replay handling matters because providers retry webhooks and networks duplicate
requests. A completed payment must not be credited again or moved backward to
pending.

Outbound merchant webhooks are queued through `DispatchMerchantWebhook`.
`SafeWebhookUrl` validates destinations and rejects private, loopback, link-local,
metadata, reserved, and non-HTTPS destinations. Redirects are disabled so an
apparently safe URL cannot redirect the server into an internal network.

## 14. CSRF, origins, and browser security

Browser authentication uses cookies, so state-changing requests require CSRF
protection. The frontend obtains the CSRF cookie and copies the decoded
`XSRF-TOKEN` value into the `X-XSRF-TOKEN` request header.

`RejectUntrustedOrigins` rejects state-changing browser requests from origins that
are not configured as trusted. Webhook routes are exempted because providers do
not have the merchant browser's CSRF cookie; they use provider signatures instead.

CSRF protects cookie-authenticated browser requests from another origin. It is
different from API-key authorization, which is intended for server-to-server
clients.

## 15. Error handling and status codes

Explain errors by category:

- `401 Unauthorized`: missing/invalid session or API key.
- `403 Forbidden`: authenticated but missing role or scope.
- `419`: CSRF/session token problem.
- `422 Unprocessable Entity`: validation or idempotency conflict.
- `429 Too Many Requests`: throttling.
- `500`: unexpected server failure.
- `503`: dependency/service unavailable.

Public responses should not contain SQL statements, stack traces, file paths, or
provider secrets. Detailed diagnostics belong in protected server logs. A log file
must be reviewed and redacted before being shared.

## 16. Frontend mechanics

### Auth context and protected layouts

`AuthContext` calls `/api/auth/me` to determine the current principal. The
dashboard layout waits while authentication is being checked and redirects
unauthenticated users. `RoleGuard` prevents rendering controls that the role
cannot use, but backend authorization remains authoritative.

### Axios behavior

`src/lib/api.ts`:

- Uses a configured `NEXT_PUBLIC_API_URL`.
- Sends `withCredentials` for browser cookies.
- Adds JSON and `Accept` headers.
- Reads the CSRF cookie and sends `X-XSRF-TOKEN`.
- On the first `401`, performs one clean redirect to `/login` or `/admin/login`.
- Uses a guard to avoid redirect loops.

After logout or a `401`, protected pages must not continue rendering stale
merchant data as if it were current.

### Loading and failure UX

The dashboard pages distinguish:

- initial loading
- empty state
- pending/processing state
- success
- failed/cancelled state
- permission failure
- expired session
- network/offline failure

Buttons disable while a mutation is in progress to reduce double submits. The
backend still needs idempotency and constraints because a disabled button cannot
protect against retries from Postman, multiple tabs, or network replay.

### Automatic refresh

Transactions, withdrawals, and active payment links poll only while they have
pending/processing records. Once records reach terminal states, polling stops.
This balances freshness with backend load.

### User-controlled values

React renders normal values as text. Avoiding unsanitized raw HTML prevents a
user-controlled merchant name, reference, or webhook message from becoming
executable markup.

## 17. A complete live demonstration script

### Part A: merchant and team

1. Log in as the merchant owner.
2. Open Team.
3. Invite a developer and a finance member.
4. Explain the invitation token, 24-hour expiry, password setup, and role.
5. Log in as the developer and show that team-management actions are forbidden.
6. Log in as finance and show withdrawal access.
7. Deactivate the finance member and show that existing access is revoked.

### Part B: API key

1. Create a test key with `payments:write` and a 30-day lifetime.
2. Explain that plaintext is shown once.
3. Use it in Postman to initialize a payment.
4. Try a withdrawal with that same key and show `403` because the scope is absent.
5. Create a key with `withdrawals:write`.
6. Demonstrate key rotation and revocation.

### Part C: idempotency

1. Send a payment request with `Idempotency-Key: demo-001`.
2. Send the exact same request again.
3. Show that no duplicate transaction is created.
4. Change the amount but keep `demo-001`.
5. Show that the fingerprint conflict is rejected.

### Part D: checkout

1. Create a payment link.
2. Open `/pay/[token]` in a clean browser window.
3. Explain that amount/reference authority comes from the backend.
4. Show pending and final status behavior.
5. Attempt a second completion and show that the terminal state is protected.

### Part E: payout

1. Open Wallet and show the available balance and ledger.
2. Log in as finance.
3. Open Withdrawals and select a bank/account.
4. Explain role/scope authentication before the controller runs.
5. Submit a payout.
6. Show the pending withdrawal, wallet debit, and ledger entry.
7. Explain provider verification/webhook completion.
8. Demonstrate insufficient balance or provider failure and explain reversal.

### Part F: isolation and security

1. Use Merchant A's session/key.
2. Attempt to request Merchant B's resource ID.
3. Show that the merchant-scoped query rejects it.
4. Remove the API scope and show `403`.
5. Log out and replay the old request to show `401`.
6. Repeat a webhook and explain replay protection.

## 18. Likely mentor questions and strong answers

### Why is a frontend role check insufficient?

The browser is untrusted. A user can edit JavaScript or call the API directly.
The backend repeats role and tenant checks before every protected operation.

### Why is a payment not successful immediately after initialization?

Initialization creates a provider checkout operation. Verification or a signed
provider webhook confirms the final payment state. This avoids trusting a client
redirect or an unverified provider response.

### Why use a ledger if the wallet has a balance?

The wallet is current state. The ledger is the audit trail. The ledger makes
credits, debits, fees, reversals, and reconciliation explainable.

### Why lock the wallet?

To prevent a lost-update or double-spend race. The row lock serializes balance
changes for that wallet inside a transaction.

### What happens if the provider times out?

The local operation can remain pending. The client can retry safely with the same
idempotency key, and later verification/webhook processing resolves the state.

### What happens if a webhook arrives twice?

The handler checks replay identity and terminal state. It does not credit or debit
the wallet twice.

### What is the biggest production limitation?

This is a sandbox prototype. Real-money operation would require provider contract
testing, multi-worker concurrency testing, reconciliation, monitoring, backups and
restore tests, rate limiting, deployment access review, incident response, and
formal compliance work.

### What would you improve next?

I would add OpenAPI documentation, provider sandbox contract fixtures, true
multi-process race tests, job failure/dead-letter monitoring, stronger mandatory
key expiration policies, reconciliation reports, and more operational telemetry.

## 19. Terms to know precisely

- **Authentication**: proving who the caller is.
- **Authorization**: deciding what an authenticated caller may do.
- **Tenant isolation**: preventing one merchant from accessing another merchant's data.
- **IDOR**: insecure direct object reference; an object ID is exposed without an
  ownership/authorization check.
- **CSRF**: cross-site request forgery against cookie-authenticated browsers.
- **Session fixation**: reusing a session identifier known before authentication.
- **Replay attack**: submitting a previously valid token, webhook, or request again.
- **Idempotency**: repeating the same operation produces one logical result.
- **Fingerprint**: a hash of important request inputs used to detect key reuse with
  different data.
- **Pessimistic locking**: locking a database row before reading/updating it.
- **Optimistic concurrency**: detecting conflicting updates at commit/version time.
- **State machine**: allowing only valid transitions such as pending -> successful
  or pending -> failed.
- **Ledger**: append-oriented accounting history of balance changes.
- **Webhook**: asynchronous server-to-server event notification.
- **Webhook signature**: cryptographic proof that the payload came from the provider.
- **SSRF**: server-side request forgery; forcing the server to call an internal URL.
- **Least privilege**: granting only the role/scope needed for an operation.
- **Defense in depth**: applying multiple independent controls instead of trusting one.

## 20. Final answer to "did you just prompt this?"

> I used tools to accelerate implementation, but I can explain the design and
> verify it through the request flow. For example, a withdrawal is not just a
> controller call: Sanctum or API-key authentication identifies the caller,
> role/scope middleware authorizes it, merchant-active middleware blocks suspended
> accounts, validation checks the bank and amount, a database transaction locks
> the wallet and records a debit, the provider adapter initiates the payout, and
> verification/webhooks finalize or reverse it. The same reasoning applies to
> API-key rotation, payment idempotency, webhook replay, and tenant isolation.

Do not claim the system is bug-free. Claim that you understand the controls,
the tested behaviors, and the known limitations.
