# Payment Gateway Security Review and Manual Test Plan

This document records the security review and implementation pass of the Laravel API and the frontend source at
`C:\Users\HP\desktop\payment-gateway-frontend\src`. No application code was changed during
the initial review; the login/session fixes listed below were applied afterward.

## Review scope

- Backend routes: [routes/api.php](../routes/api.php)
- Backend application services, controllers, jobs, models, and provider integrations
- Frontend source: [payment-gateway-frontend/src](C:/Users/HP/desktop/payment-gateway-frontend/src)
- Authentication, authorization, tenant isolation, payment and payout state changes
- Webhooks, logs, browser behavior, and checkout UX

## Security findings

| # | Severity | File | Lines | Vulnerability | Confidence |
|---|----------|------|-------|---------------|------------|
| 1 | CRITICAL | [CrayProvider.php](../app/Services/PaymentProviders/Cray/CrayProvider.php) | 176-187 | Refund and payout operations return unconditional success without moving money or independently verifying provider status. | 10/10 |
| 2 | HIGH | [CrayProvider.php](../app/Services/PaymentProviders/Cray/CrayProvider.php) | 41 | `Http::withoutVerifying()` disables TLS certificate validation for all Cray requests, exposing credentials and payment responses to interception. | 10/10 |
| 3 | HIGH | [MerchantSettingsController.php](../app/Http/Controllers/Api/Settings/MerchantSettingsController.php) and [api.php](../routes/api.php) | 22-46; 61-69 | The general settings endpoint exposes the complete webhook signing secret to every authenticated merchant user, including lower-privileged roles. | 9/10 |
| 4 | HIGH | [WebhookSettingsController.php](../app/Http/Controllers/Api/WebhookSettingsController.php) and [DispatchMerchantWebhook.php](../app/Jobs/DispatchMerchantWebhook.php) | 34-50; 54-70 | An arbitrary merchant-controlled webhook URL is requested server-side and part of its response is stored and exposed, creating an SSRF and internal-response disclosure primitive. | 8/10 |
| 5 | MEDIUM | [PaystackProvider.php](../app/Services/PaymentProviders/Paystack/PaystackProvider.php) | 148 | Full payout recipient data, including bank account details and name, is written to logs. | 9/10 |

## Login/session implementation pass

The following authentication issues were fixed in the current pass:

- Device OTP challenges are now opaque, one-time values bound to the login IP and user-agent
  instead of accepting a user-controlled merchant ID.
- Owner and team-member logins use separate known-device records, and device verification
  rejects suspended merchants and deactivated team members.
- Logout revokes the current Sanctum bearer token, and session-management routes no longer
  dereference a missing token. The `revoke-others` route is now registered.
- Merchant status and active-state checks run before login and on protected requests.

Runtime validation is still required for the cases in section 3 using two isolated browser
profiles and separate accounts. The current environment cannot run the existing SQLite
feature tests because the PHP SQLite driver is unavailable.

## Recommended fixes

### P0 - block live money movement until corrected

1. Disable Cray refunds and payouts in every environment that can handle real money.
2. Replace the unconditional mock responses with real provider calls and authenticated,
   independently verified status responses.
3. Treat an incomplete provider operation as a failure or an explicit pending state; never
   return `SUCCESSFUL` merely because a local reference exists.
4. Ensure wallet debit, payout creation, provider initialization, and reversal are
   idempotent and transactionally safe.
5. Add an operational feature flag that fails closed when a provider capability is not
   implemented.

### P1 - restore transport and access-control boundaries

1. Remove `withoutVerifying()` from the Cray HTTP client. Use normal certificate validation.
   If a private CA is genuinely required, configure and pin the trusted CA rather than
   disabling verification.
2. Stop returning the raw webhook secret from the general settings response. Return a masked
   value and allow generation, reveal, and rotation only to the minimum administrative role.
3. Prefer one-time secret display after generation, with re-authentication or MFA for reveal
   and rotation. Never include the secret in ordinary dashboard payloads.
4. Restrict webhook delivery destinations to an HTTPS allowlist where possible. Resolve the
   destination before connecting and reject loopback, private, link-local, reserved, and
   IPv6 private ranges.
5. Disable unsafe redirects or validate every redirect destination. Re-check DNS/IP safety
   at connection time to reduce DNS rebinding risk.
6. Do not persist arbitrary webhook response bodies. Store status, timing, request ID, and a
   short sanitized diagnostic instead.

### P2 - reduce financial-data exposure

1. Remove the full payout payload from logs.
2. If diagnostic logging is needed, log only a masked account suffix, provider request ID,
   merchant ID, and a correlation ID.
3. Review existing retained logs and rotate or purge any logs containing full account
   numbers according to the data-retention policy.

### P3 - verification and regression coverage after fixes

1. Add automated tests for each finding after the manual test pass.
2. Add concurrency tests for duplicate verification, duplicate webhooks, and competing
   withdrawals.
3. Add authorization tests for every role and every merchant-owned resource.
4. Add provider contract tests that reject malformed, mismatched, and unsigned responses.

## Manual test procedure

### Test preparation

Use a local or isolated staging environment with test provider credentials. Do not use
production money or real bank accounts. Keep a test record containing:

- Browser, OS, frontend commit, backend commit, and environment name
- Merchant IDs, user roles, transaction references, and provider references
- Request timestamps and correlation IDs
- Expected result, observed result, screenshots, and API response status

Create at least two merchants (Merchant A and Merchant B), and users with owner, admin,
developer, finance, and ordinary team-member roles. Create separate browser profiles for
each account. Enable browser network inspection and application logs, but do not paste
secrets into the test record.

### 1. Environment and transport

- Confirm production-like configuration has `APP_DEBUG=false`, non-default credentials,
  correct frontend/API URLs, and HTTPS everywhere.
- Confirm CORS allows only the intended frontend origin and does not reflect arbitrary
  origins when credentials are enabled.
- Confirm cookies are `Secure`, `HttpOnly`, and have the intended `SameSite` policy.
- Confirm API keys, webhook secrets, passwords, session tokens, and full bank details do
  not appear in browser storage, HTTP responses, application logs, queue logs, or errors.
- Against a safe test endpoint, use an invalid provider certificate and verify the request
  fails closed. Do not proceed with live provider traffic if it succeeds.

### 2. Registration and email verification

- Register valid Merchant A data and confirm merchant, wallet, settings, and OTP creation
  are atomic.
- Submit duplicate registrations for the same email during the OTP window. Confirm the
  existing registration cannot be overwritten.
- Try invalid-length, non-numeric, wrong, expired, reused, and cross-merchant OTPs.
- Resend OTP repeatedly and confirm throttling plus invalidation of the previous OTP.
- Try login and protected API calls before verification; they must be rejected.
- Repeat registration with the same `Idempotency-Key`; only one merchant must be created.

### 3. Login, sessions, devices, and logout

- Log in from a new IP/user-agent and confirm device OTP is required.
- Verify known-device login, session creation only after OTP, OTP expiry, wrong OTP, reuse,
  and cross-account merchant IDs.
- Test suspended merchants and deactivated team members.
- Log out, replay an authenticated request, and confirm the session is invalid.
- Confirm session identifiers rotate after login and old identifiers cannot be reused.
- Test concurrent logins and session revocation through the session-management endpoint.

### 4. CSRF and browser authentication

- Fetch the CSRF cookie, then submit state-changing requests without the CSRF header.
  Confirm rejection.
- Submit authenticated POST, PATCH, and DELETE requests from an untrusted origin.
- Test login CSRF using a cross-site form and confirm the intended protection.
- Force a 401 during an in-flight frontend request. Confirm one clean redirect to login,
  no redirect loop, and no stale protected data rendered afterward.

Implementation and automated coverage:

- State-changing API requests with an `Origin` header are restricted to the configured
  application and frontend origins. Webhook delivery routes remain exempt because they
  authenticate with their own signature scheme.
- The frontend Axios interceptor allows only one 401 redirect at a time and rejects the
  original request instead of leaving it pending indefinitely.
- Automated coverage is in
  [CsrfAndBrowserAuthenticationTest.php](../tests/Feature/Auth/CsrfAndBrowserAuthenticationTest.php).
  It covers missing CSRF tokens, authenticated POST/PATCH/DELETE requests from an
  untrusted origin, and login CSRF.

### 5. Role and tenant isolation

For every role, exercise every route in [routes/api.php](../routes/api.php) and record the
expected status code. Confirm:

- Owners can perform only intended merchant operations.
- Admins can manage only the documented team, API-key, webhook, and money operations.
- Developers cannot initiate withdrawals or alter balances.
- Finance users cannot manage API keys, webhook secrets, or team membership.
- Ordinary team members cannot access merchant APIs.
- Merchant A cannot read, update, verify, retry, or delete Merchant B transactions,
  payment links, withdrawals, API keys, webhook deliveries, or settings.
- Replacing every UUID/path ID with a Merchant B ID never returns B's data or changes B's
  state.
- Non-merchant principals cannot accidentally pass merchant-only authentication middleware.

Implementation and automated coverage:

- Merchant UI controllers now ignore a client-supplied `merchant` field when a browser
  principal is authenticated. Team members always resolve through their own merchant
  relationship; only API-key middleware may provide request merchant context.
- Merchant-only middleware rejects non-merchant principals, including admin users, before
  controller execution.
- Role checks allow owners, enforce the declared team roles, and reject undeclared roles.
- Automated coverage is in
  [RoleTenantIsolationTest.php](../tests/Feature/Auth/RoleTenantIsolationTest.php).
  It covers owner access, admin/developer/finance role boundaries, non-merchant rejection,
  inactive merchants, and attempted cross-merchant context injection.

### 6. API keys and scopes

- Create a key with each individual scope and verify only matching routes work.
- Try adding extra scopes in the request body; the server must ignore or reject escalation.
- Verify revoked and expired keys fail immediately, including at expiry boundaries.
- Rotate a key and confirm the old secret fails immediately.
- Try API-key access to Merchant B resource IDs.
- Confirm only the creation response can show a plaintext key and that hashes are never
  returned.

Implementation and automated coverage:

- Live keys now use the `sk_live_` prefix and test keys use `sk_test_`.
- API-key scopes are allowlisted server-side to the scopes implemented by the routes;
  wildcard and unsupported scopes are rejected.
- Revoked and expired keys are rejected by API-key middleware, and rotation replaces the
  stored hash so the old secret stops working immediately.
- API-key resources omit `plain_text_key` unless it was explicitly attached for the
  creation or rotation response. Stored SHA-256 hashes are never serialized.
- Merchant-owned API resources remain scoped through the API key's merchant context.
- Automated coverage is in
  [ApiKeySecurityTest.php](../tests/Feature/Auth/ApiKeySecurityTest.php). It covers
  environment prefixes, plaintext exposure, scope enforcement, revocation, expiry,
  rotation, and cross-merchant resource access.

### 7. Payment initialization

- Try zero, negative, fractional, extremely large, and excessive-precision amounts.
- Try unsupported currencies and currencies without a configured wallet.
- Repeat duplicate references and idempotency keys with identical and conflicting payloads.
- Force provider initialization failure and confirm the transaction state is consistent.
- Attempt to use metadata to change ownership, amount, currency, provider, status, or ledger
  behavior.
- Test callback URLs and metadata for unsafe reflection or redirects.
- Confirm provider errors do not expose credentials, raw responses, stack traces, or SQL.

Implementation and automated coverage:

- Amounts must be positive, limited to two decimal places, and bounded to prevent
  oversized values. Currency must have a configured merchant wallet.
- Callback URLs are restricted to HTTP(S), and metadata is stored as data only; it cannot
  override merchant ownership, amount, currency, provider, status, or ledger fields.
- Idempotency keys are fingerprinted against the validated payload. Reusing a key with
  different payment data returns a conflict instead of replaying or mutating a payment.
- Provider exceptions and malformed provider responses transition the transaction to
  `FAILED` and return a generic 502 response without provider response bodies or secrets.
- Automated coverage is in
  [PaymentInitializationSecurityTest.php](../tests/Feature/Auth/PaymentInitializationSecurityTest.php).

### 8. Payment verification and ledger integrity

- Verify a successful payment and confirm exactly one wallet credit.
- Replay manual verification and the provider webhook concurrently; there must be one
  ledger entry and one final state transition.
- Test pending, failed, cancelled, unknown, malformed, and delayed provider statuses.
- Alter provider amount, currency, fee, reference, and merchant identifiers. All mismatches
  must be rejected.
- Test missing, negative, and fee-greater-than-amount values.
- Confirm a successful transaction cannot move back to failed.
- Verify payment-link completion remains scoped to the correct merchant and transaction.

Implementation and automated coverage:

- Manual verification and payment webhooks lock the transaction before applying a final
  state transition. A successful transaction is not processed again, and the unique
  `ldg_<transaction-id>` ledger reference plus wallet row lock enforce one credit.
- Successful provider results must include the expected amount. If present, currency and
  provider reference must also match the local transaction. Fees must be numeric,
  non-negative, and no greater than the payment amount.
- Provider verification failures return a generic gateway error without exposing provider
  exception text. Payment-link completion is additionally constrained by merchant ID and
  the transaction currently being linked.
- Regression coverage for mismatched provider data, replayed verification, exactly-once
  ledger crediting, and cross-merchant payment-link protection is in
  [PaymentInitializationSecurityTest.php](../tests/Feature/Auth/PaymentInitializationSecurityTest.php).
- True multi-process concurrency should still be exercised in staging against the
  production database engine; the automated test verifies the replay/state-machine path
  and the database locks are the enforcement mechanism.

### 9. Webhook verification and replay

- Send unsigned webhooks, invalid signatures, altered bodies, missing hashes, and malformed
  JSON.
- Verify Paystack signs the exact raw request body and Cray uses the expected canonical
  serialization.
- Send a Cray webhook to the Paystack route and vice versa; provider routing and signature
  verification must not be interchangeable.
- Replay valid payment and withdrawal webhooks.
- Send valid signatures for unknown references and another merchant's references; no state
  change must occur.
- Alter webhook amount, fee, currency, and reference; all mismatches must be rejected.
- Replay failed withdrawal webhooks and confirm reversal occurs once.

Implementation and automated coverage:

- The webhook route now selects the verifier from its `{provider}` route value. Paystack
  payloads cannot be verified by the Cray implementation, or vice versa.
- Unsupported providers, unsigned or invalidly signed payloads, unknown event types, and
  payloads without a provider reference are rejected before any transaction lookup or
  state mutation.
- Payment webhook amount, currency, fee, and reference checks are enforced before success
  transitions. Withdrawal amount and currency mismatches are rejected as well.
- Transaction and withdrawal rows are locked during processing. Final-state checks plus
  unique ledger references make valid webhook replays no-ops; failed withdrawal replay
  cannot create a second reversal.
- Focused routing and malformed-payload coverage is in
  [WebhookSecurityTest.php](../tests/Feature/Auth/WebhookSecurityTest.php). Payment
  mismatch, replay, and exactly-once ledger coverage is in
  [PaymentInitializationSecurityTest.php](../tests/Feature/Auth/PaymentInitializationSecurityTest.php).
- Provider contract tests should still be run with real signed fixtures for both providers
  in staging before production enablement.

### 10. Withdrawals and payouts

- Test below-minimum, unsupported-currency, malformed-bank-code, invalid-account-number,
  duplicate-reference, and insufficient-balance requests.
- Confirm wallet debit and withdrawal creation are atomic.
- Force provider initialization failure and confirm funds are reversed exactly once.
- Run competing withdrawals against one wallet and confirm the balance cannot go negative.
- Verify status transitions require authenticated provider responses.
- In a non-production sandbox, confirm a Cray payout is never marked successful without an
  actual provider transfer.
- Replay failed payout webhooks and confirm idempotent reversal.
- Inspect logs and confirm no full account number or recipient payload is stored.

Implementation and automated coverage:

- Withdrawal amounts require the configured minimum and no more than two decimal places;
  account numbers must contain digits only, and the merchant wallet is locked during the
  debit. The debit and withdrawal creation are transactional.
- The selected provider is stored on each withdrawal. Initialization requires a successful
  provider response with a reference, a pending/processing status, and a non-negative fee.
  Provider failure marks the withdrawal failed and credits the debit back using a unique
  reversal reference.
- Verification uses the withdrawal's provider and requires a provider reference. Provider
  errors are logged by exception class and return a generic response. Only explicit
  success, failed, reversed, or rejected statuses can change state.
- Cray payouts and payout verification now fail closed because those operations are not
  implemented; they cannot report a local payout as successful without a provider transfer.
- Paystack payout logs contain only the currency and final four account digits; complete
  bank payloads and provider response bodies are not logged.
- Withdrawal webhook amount and currency must match the local withdrawal. Row locks,
  terminal-state checks, and unique reversal references make failed-payout replay
  idempotent.
- Regression coverage is in
  [WithdrawalSecurityTest.php](../tests/Feature/Auth/WithdrawalSecurityTest.php).

PostgreSQL validation:

- The complete feature security suite was run against the isolated `mini_gateway_test`
  PostgreSQL database, including the withdrawal and ledger tests. It passed with 34 tests
  and 129 assertions.

### 11. Hosted checkout and payment links

- Open valid, expired, completed, cancelled, malformed, and random tokens.
- Confirm expired links cannot be processed and are marked expired.
- Submit one link concurrently from multiple tabs; verify the permitted transaction count
  cannot be exceeded.
- Test customer name/email validation, oversized values, and invalid encodings.
- Tamper with amount and currency in the browser; server-side values must win.
- Test callbacks with missing, foreign, forged, and replayed references.
- Test configured success, cancel, and redirect URLs for open redirects.
- Confirm the success page reflects server-verified status, not only a query-string reference.
- Refresh, use browser back/forward, disconnect the network, and retry during checkout.

Implementation and automated coverage:

- Checkout processing re-checks the link inside a row-locked transaction. A completed,
  deactivated, expired, or already-used link cannot create another transaction, including
  when multiple tabs submit at the same time.
- Amount, currency, merchant ownership, and payment-link identity are taken from the
  server-side link record; customer input is limited to validated name and email fields.
- Provider initialization failures mark the created checkout transaction failed and return a
  generic error. Callback verification uses the transaction's recorded provider and
  rejects successful responses with missing or mismatched amount, currency, or fee data.
- Ledger-credit failures are no longer swallowed. The callback transaction rolls back and
  redirects to the failure page instead of presenting a false successful checkout.
- Configured redirect destinations must be HTTPS. Invalid or non-HTTPS destinations fall
  back to the trusted frontend success/failure pages, preventing open redirects.
- Callback references are constrained to the selected token's merchant and transaction
  records. Unknown and foreign references cannot change another merchant's payment.
- The frontend hosted-checkout page displays server-loaded checkout data and redirects only
  to the checkout URL returned by the API; it does not trust browser-supplied amount or
  currency values.

PostgreSQL validation:

- The complete feature security suite was run against the isolated `mini_gateway_test`
  PostgreSQL database. It passed with 34 tests and 129 assertions.

### 12. SSRF and webhook delivery

In an isolated environment, configure webhook destinations for:

- `127.0.0.1`, `localhost`, private RFC1918 addresses, and IPv6 loopback/private ranges
- Link-local cloud metadata addresses
- A hostname resolving to a private address
- A public URL redirecting to a private address

Trigger a webhook and verify unsafe destinations are rejected, no internal response body is
returned through delivery history, redirects are safe, and retries do not duplicate
financial state changes.

Implementation:

- Webhook configuration and queued delivery require HTTPS and resolve every A/AAAA
  address before delivery. Loopback, RFC1918, link-local, carrier-grade NAT, unique-local,
  IPv6 loopback, IPv6 link-local, and IPv4-mapped private addresses are rejected.
- The delivery client does not follow redirects, preventing a public URL from redirecting
  the worker into an internal address. The destination is validated again in the job so
  old or directly-created settings cannot bypass request validation.
- Delivery history no longer stores response bodies or exception text. HTTP responses have
  a null body and transport failures use a generic diagnostic. Logs record only the
  destination host and exception class.
- Unsafe destinations are recorded as rejected deliveries without making an outbound
  request. Delivery retries only retry notification delivery and do not mutate payment,
  withdrawal, or ledger state.

### 13. Frontend security and UX

- Inspect local/session storage and confirm no API keys, passwords, webhook secrets, or
  session tokens are persisted.
- Visit protected dashboard/admin URLs while logged out and after logout; confirm no stale
  cached page is usable.
- Force 401, 403, 419, 422, 429, 500, timeout, and offline responses. Confirm each has a
  clear, non-technical message and an actionable recovery path.
- Confirm user-controlled values render as text rather than HTML.
- Double-click payment, withdrawal, API-key rotation, webhook retry, and save buttons.
  Confirm one server operation and clear loading states.
- Test mobile layouts, keyboard navigation, focus order, visible validation, contrast,
  disabled controls, and screen-reader labels.
- Test slow network, provider timeout, refresh during payment, and browser back/forward.
- Confirm the UI never claims success before server verification and clearly distinguishes
  pending, failed, cancelled, and successful states.

Implementation:

- Browser authentication uses HttpOnly/SameSite cookies and does not persist session
  identifiers, API keys, passwords, or webhook secrets in local storage. Registration
  recovery storage contains only non-sensitive form fields and is removed when read.
- Protected dashboard providers clear the authenticated user before redirecting after a
  401 and render no protected children while unauthenticated, preventing stale protected
  data during redirects. Redirects use replacement navigation to avoid back-button loops.
- API configuration now uses `NEXT_PUBLIC_API_URL` instead of hard-coding the local API
  host. The Axios interceptor keeps a single 401 redirect in flight.
- Payment checkout disables its submit button while the provider session is being created;
  API-key, withdrawal, provider, and other mutation screens use processing/loading guards.
  User-controlled values are rendered as React text rather than HTML.
- Existing frontend production build and TypeScript validation pass. ESLint still reports
  pre-existing errors in unrelated dashboard files (explicit `any`, unescaped documentation
  quotes, and state updates in effects); those should be cleaned up before treating lint
  as a release gate.

## Exit criteria for the mentor demonstration

Do not demonstrate live payout success until the mock payout behavior is removed or clearly
disabled. Before presenting, have a completed record showing:

1. The happy path from registration through verified checkout and ledger credit.
2. A rejected unauthorized cross-merchant request.
3. A rejected duplicate/idempotency request.
4. A rejected invalid webhook or provider mismatch.
5. A safe provider failure with no incorrect wallet balance change.
6. A clean logout and expired-session behavior.
7. No secrets or full financial identifiers in browser storage, responses, or logs.
