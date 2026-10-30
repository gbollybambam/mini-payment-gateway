# Mini Payment Gateway

A portfolio payment-gateway prototype demonstrating merchant accounts, team roles,
scoped API keys, hosted checkout, provider webhooks, wallet/ledger updates,
withdrawals, and B2B API integration.

This is a **sandbox/demo project**. It is not a licensed payment processor and
must not be used for real customer funds or production financial traffic.

## Stack

- Laravel 13 / PHP 8.3
- PostgreSQL 17
- Laravel Sanctum
- Database queues
- Next.js / React / TypeScript frontend
- Paystack and Cray provider adapters
- Docker Compose

## Run with Docker

1. Copy the deployment template:

   ```powershell
   Copy-Item .env.docker.example .env
   ```

2. Generate an application key and place it in `.env`:

   ```powershell
   php artisan key:generate --show
   ```

3. Set sandbox provider credentials in `.env` if provider calls are required.
   Never commit `.env` or real credentials.

4. Start the stack:

   ```powershell
   docker compose up -d --build
   ```

5. Open:

   - Frontend: http://localhost:3000
   - Backend health check: http://localhost:8080/up
   - Mailpit inbox: http://localhost:8025

The stack includes PostgreSQL, the Laravel API, a database queue worker, the
Next.js frontend, and Mailpit. Mailpit captures demo email locally without
requiring a paid email provider.

Useful commands:

```powershell
docker compose ps
docker compose logs -f backend
docker compose logs -f queue
docker compose down
```

## B2B API example

Create a test API key in the dashboard, then send:

```http
POST http://localhost:8080/api/v1/payments
Authorization: Bearer YOUR_TEST_API_KEY
Content-Type: application/json
Idempotency-Key: demo-order-10001
```

```json
{
  "amount": 5000,
  "currency": "NGN",
  "customer_email": "customer@example.com",
  "reference": "demo-order-10001",
  "metadata": {
    "order_id": "ORDER-10001"
  }
}
```

The API returns a payment ID and checkout URL. Use the payment ID with
`GET /api/v1/payments/{id}` to query status. See
`manual-review/SECURITY-AND-MANUAL-TEST-PLAN.md` for the broader test plan.

## Email and webhooks

For a public deployment, configure a real SMTP provider if visitors need to
receive verification or invitation emails. Mailpit is intentionally local-only.

Configure provider webhooks to:

```text
https://YOUR_BACKEND_DOMAIN/api/webhooks/paystack
https://YOUR_BACKEND_DOMAIN/api/webhooks/cray
```

The queue worker must remain running for queued merchant webhook delivery.

## Security and demo limitations

- Use sandbox/test provider credentials only.
- Set `APP_DEBUG=false` outside local development.
- Use HTTPS and deployment-managed secrets.
- Do not expose Mailpit publicly.
- Do not use real bank accounts or customer funds.
- Provider capabilities that are not implemented should remain disabled.

## Contributing

Issues and pull requests are welcome. Please do not include credentials,
customer data, or provider secrets in issues, commits, screenshots, or logs.

## License

This project is released under the MIT License. See [LICENSE](LICENSE).
