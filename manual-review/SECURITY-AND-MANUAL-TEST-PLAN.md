# Payment Gateway Security Review and Manual Test Plan

This document records the read-only review of the Laravel API and the frontend source at
`C:\Users\HP\desktop\payment-gateway-frontend\src`. No application code was changed during
the review.

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

### 6. API keys and scopes

- Create a key with each individual scope and verify only matching routes work.
- Try adding extra scopes in the request body; the server must ignore or reject escalation.
- Verify revoked and expired keys fail immediately, including at expiry boundaries.
- Rotate a key and confirm the old secret fails immediately.
- Try API-key access to Merchant B resource IDs.
- Confirm only the creation response can show a plaintext key and that hashes are never
  returned.

### 7. Payment initialization

- Try zero, negative, fractional, extremely large, and excessive-precision amounts.
- Try unsupported currencies and currencies without a configured wallet.
- Repeat duplicate references and idempotency keys with identical and conflicting payloads.
- Force provider initialization failure and confirm the transaction state is consistent.
- Attempt to use metadata to change ownership, amount, currency, provider, status, or ledger
  behavior.
- Test callback URLs and metadata for unsafe reflection or redirects.
- Confirm provider errors do not expose credentials, raw responses, stack traces, or SQL.

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

### 12. SSRF and webhook delivery

In an isolated environment, configure webhook destinations for:

- `127.0.0.1`, `localhost`, private RFC1918 addresses, and IPv6 loopback/private ranges
- Link-local cloud metadata addresses
- A hostname resolving to a private address
- A public URL redirecting to a private address

Trigger a webhook and verify unsafe destinations are rejected, no internal response body is
returned through delivery history, redirects are safe, and retries do not duplicate
financial state changes.

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
