<?php

namespace Tests\Feature\Auth;

use App\Models\Merchant;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\PaymentLink;
use App\Enums\PaymentStatus;
use App\Services\PaymentProviders\ProviderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PaymentInitializationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_amounts_and_callback_protocols_are_rejected(): void
    {
        $merchant = $this->merchant();
        $this->actingAs($merchant, 'web');
        $token = $this->createApiKey();

        foreach ([0, -1, '1.001', '1000000000000000'] as $amount) {
            $this->withToken($token)->postJson('/api/v1/payments', $this->payload(['amount' => $amount]))
                ->assertUnprocessable();
        }

        $this->withToken($token)->postJson('/api/v1/payments', $this->payload([
            'callback_url' => 'javascript:alert(1)',
        ]))->assertUnprocessable();
    }

    public function test_idempotency_key_rejects_conflicting_payloads(): void
    {
        $merchant = $this->merchant();
        $this->actingAs($merchant, 'web');
        $token = $this->createApiKey();
        $this->fakeProvider();

        $headers = ['Idempotency-Key' => 'payment-key-1'];
        $this->withToken($token)->postJson('/api/v1/payments', $this->payload(), $headers)->assertCreated();

        $this->withToken($token)->postJson('/api/v1/payments', $this->payload([
            'amount' => 250,
        ]), $headers)->assertConflict();

        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_provider_failure_marks_transaction_failed_without_leaking_provider_details(): void
    {
        $merchant = $this->merchant();
        $this->actingAs($merchant, 'web');
        $token = $this->createApiKey();

        $provider = Mockery::mock(\App\Services\PaymentProviders\Contracts\PaymentProviderInterface::class);
        $provider->shouldReceive('initializePayment')->once()->andThrow(new \RuntimeException('secret provider response'));
        $factory = Mockery::mock(ProviderFactory::class);
        $factory->shouldReceive('getActiveProviderName')->once()->andReturn('paystack');
        $factory->shouldReceive('make')->once()->andReturn($provider);
        $this->app->instance(ProviderFactory::class, $factory);

        $response = $this->withToken($token)->postJson('/api/v1/payments', $this->payload());

        $response->assertStatus(502)
            ->assertJsonPath('message', 'Payment provider initialization failed. Please try again.')
            ->assertJsonMissing(['secret provider response']);
        $this->assertDatabaseHas('transactions', ['status' => 'FAILED']);
    }

    public function test_metadata_cannot_override_server_owned_transaction_fields(): void
    {
        $merchant = $this->merchant();
        $this->actingAs($merchant, 'web');
        $token = $this->createApiKey();
        $this->fakeProvider();

        $response = $this->withToken($token)->postJson('/api/v1/payments', $this->payload([
            'metadata' => [
                'merchant_id' => 'other-merchant',
                'amount' => 999999,
                'currency' => 'USD',
                'provider' => 'cray',
                'status' => 'SUCCESS',
            ],
        ]))->assertCreated();

        $transaction = Transaction::findOrFail($response->json('data.payment_id'));
        $this->assertSame($merchant->id, $transaction->merchant_id);
        $this->assertSame('NGN', $transaction->currency);
        $this->assertSame('paystack', $transaction->provider);
        $this->assertSame('PENDING', $transaction->status->value);
    }

    public function test_verification_rejects_provider_amount_and_fee_mismatches(): void
    {
        $merchant = $this->merchant();
        $this->actingAs($merchant, 'web');
        $token = $this->postJson('/api/api-keys', [
            'name' => 'Verification test key',
            'environment' => 'test',
            'scopes' => ['payments:read'],
        ])->assertCreated()->json('data.plain_text_key');

        $transaction = Transaction::create([
            'merchant_id' => $merchant->id,
            'provider' => 'paystack',
            'customer_email' => 'customer@example.com',
            'reference' => 'verification-' . uniqid(),
            'provider_reference' => 'provider-' . uniqid(),
            'amount' => 100,
            'currency' => 'NGN',
            'status' => PaymentStatus::PENDING,
        ]);

        $provider = Mockery::mock(\App\Services\PaymentProviders\Contracts\PaymentProviderInterface::class);
        $provider->shouldReceive('verifyPayment')->once()->andReturn([
            'success' => true,
            'status' => PaymentStatus::SUCCESS->value,
            'provider_reference' => $transaction->provider_reference,
            'amount' => 101,
            'currency' => 'NGN',
            'fee' => 1,
        ]);
        $factory = Mockery::mock(ProviderFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($provider);
        $this->app->instance(ProviderFactory::class, $factory);

        $this->withToken($token)->getJson("/api/v1/payments/{$transaction->id}/verify")
            ->assertStatus(422);

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'status' => PaymentStatus::PENDING->value,
        ]);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_successful_verification_credits_once_and_scopes_payment_link(): void
    {
        $merchant = $this->merchant();
        $otherMerchant = $this->merchant();
        $this->actingAs($merchant, 'web');
        $token = $this->postJson('/api/api-keys', [
            'name' => 'Verification replay key',
            'environment' => 'test',
            'scopes' => ['payments:read'],
        ])->assertCreated()->json('data.plain_text_key');

        $otherLink = PaymentLink::create([
            'merchant_id' => $otherMerchant->id,
            'reference' => 'link-' . uniqid(),
            'checkout_token' => 'token-' . uniqid(),
            'amount' => 100,
            'currency' => 'NGN',
            'description' => 'Test payment link',
            'status' => \App\Enums\PaymentLinkStatus::ACTIVE,
        ]);
        $transaction = Transaction::create([
            'merchant_id' => $merchant->id,
            'provider' => 'paystack',
            'customer_email' => 'customer@example.com',
            'reference' => 'verification-replay-' . uniqid(),
            'provider_reference' => 'provider-replay-' . uniqid(),
            'amount' => 100,
            'currency' => 'NGN',
            'status' => PaymentStatus::PENDING,
            'metadata' => ['payment_link_id' => $otherLink->id],
        ]);

        $provider = Mockery::mock(\App\Services\PaymentProviders\Contracts\PaymentProviderInterface::class);
        $provider->shouldReceive('verifyPayment')->once()->andReturn([
            'success' => true,
            'status' => PaymentStatus::SUCCESS->value,
            'provider_reference' => $transaction->provider_reference,
            'amount' => 100,
            'currency' => 'NGN',
            'fee' => 5,
        ]);
        $factory = Mockery::mock(ProviderFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($provider);
        $this->app->instance(ProviderFactory::class, $factory);

        $this->withToken($token)->getJson("/api/v1/payments/{$transaction->id}/verify")->assertOk();
        $this->withToken($token)->getJson("/api/v1/payments/{$transaction->id}/verify")->assertOk();

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'status' => PaymentStatus::SUCCESS->value,
            'net_amount' => 95,
        ]);
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertDatabaseHas('wallets', [
            'merchant_id' => $merchant->id,
            'currency' => 'NGN',
            'available_balance' => 95,
        ]);
        $this->assertDatabaseHas('payment_links', [
            'id' => $otherLink->id,
            'status' => \App\Enums\PaymentLinkStatus::ACTIVE->value,
            'payment_id' => null,
        ]);
    }

    private function fakeProvider(): void
    {
        $provider = Mockery::mock(\App\Services\PaymentProviders\Contracts\PaymentProviderInterface::class);
        $provider->shouldReceive('initializePayment')->andReturn([
            'success' => true,
            'provider_reference' => 'provider-ref',
            'checkout_url' => 'https://checkout.example.test/session',
        ]);
        $factory = Mockery::mock(ProviderFactory::class);
        $factory->shouldReceive('getActiveProviderName')->andReturn('paystack');
        $factory->shouldReceive('make')->andReturn($provider);
        $this->app->instance(ProviderFactory::class, $factory);
    }

    private function createApiKey(): string
    {
        return $this->postJson('/api/api-keys', [
            'name' => 'Payment initialization test key',
            'environment' => 'test',
            'scopes' => ['payments:write'],
        ])->assertCreated()->json('data.plain_text_key');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'amount' => 100,
            'currency' => 'NGN',
            'customer_email' => 'customer@example.com',
            'reference' => 'payment-' . uniqid(),
            'callback_url' => 'https://merchant.example/callback',
            'metadata' => ['order_id' => 'order-1'],
        ], $overrides);
    }

    private function merchant(): Merchant
    {
        $merchant = Merchant::create([
            'business_name' => 'Example Payments',
            'email' => 'owner-' . uniqid() . '@example.com',
            'phone' => '+2348012345678',
            'country' => 'NG',
            'currency' => 'NGN',
            'business_type' => 'Individual',
            'password' => 'SecurePassword!123',
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        Wallet::create([
            'merchant_id' => $merchant->id,
            'currency' => 'NGN',
            'available_balance' => 0,
            'pending_balance' => 0,
        ]);

        return $merchant;
    }
}
