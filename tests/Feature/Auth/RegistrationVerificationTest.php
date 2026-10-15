<?php

namespace Tests\Feature\Auth;

use App\Models\Merchant;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_normalizes_email_and_provisions_the_account(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/auth/register', $this->registrationPayload([
            'email' => '  OWNER@EXAMPLE.COM ',
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.merchant_id', fn ($id) => is_string($id));

        $merchant = Merchant::query()->firstOrFail();

        $this->assertSame('owner@example.com', $merchant->email);
        $this->assertNull($merchant->email_verified_at);
        $this->assertDatabaseHas('wallets', [
            'merchant_id' => $merchant->id,
            'currency' => 'NGN',
        ]);
        Notification::assertSentTo($merchant, \App\Notifications\Auth\SendOtpNotification::class);
    }

    public function test_otp_email_explains_that_resending_invalidates_the_previous_code(): void
    {
        $merchant = new Merchant([
            'business_name' => 'Example Payments',
            'email' => 'owner@example.com',
        ]);

        $mail = (new \App\Notifications\Auth\SendOtpNotification('123456'))
            ->toMail($merchant)
            ->render();

        $this->assertStringContainsString(
            'Requesting a new code invalidates the previous one.',
            $mail
        );
    }

    public function test_verification_accepts_the_current_otp_once_and_clears_it(): void
    {
        Notification::fake();

        $registration = $this->postJson('/api/auth/register', $this->registrationPayload());
        $merchantId = $registration->json('data.merchant_id');
        $merchant = Merchant::findOrFail($merchantId);

        Notification::assertSentTo(
            $merchant,
            \App\Notifications\Auth\SendOtpNotification::class,
            function ($notification) use ($merchantId) {
                Cache::put('otp_verify_' . $merchantId, $notification->otp, now()->addMinutes(5));
                return true;
            }
        );

        $code = Cache::get('otp_verify_' . $merchantId);
        $response = $this->postJson('/api/auth/email/verify-otp', [
            'merchant_id' => $merchantId,
            'code' => $code,
        ]);

        $response->assertOk();
        $this->assertNotNull($merchant->refresh()->email_verified_at);
        $this->assertNull(Cache::get('otp_verify_' . $merchantId));
    }

    public function test_active_unverified_registration_cannot_be_overwritten(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/register', $this->registrationPayload())
            ->assertCreated();

        $this->postJson('/api/auth/register', $this->registrationPayload([
            'business_name' => 'Replacement Payments',
        ]))
            ->assertConflict();

        $this->assertDatabaseCount('merchants', 1);
        $this->assertDatabaseHas('merchants', ['business_name' => 'Example Payments']);
    }

    public function test_verified_email_cannot_register_again(): void
    {
        Notification::fake();

        $registration = $this->postJson('/api/auth/register', $this->registrationPayload());
        Merchant::findOrFail($registration->json('data.merchant_id'))
            ->forceFill(['email_verified_at' => now()])
            ->save();

        $this->postJson('/api/auth/register', $this->registrationPayload())
            ->assertUnprocessable();

        $this->assertDatabaseCount('merchants', 1);
    }

    public function test_unverified_owner_login_returns_the_verification_challenge(): void
    {
        Notification::fake();

        $registration = $this->postJson('/api/auth/register', $this->registrationPayload());

        $this->postJson('/api/auth/login', [
            'email' => ' OWNER@EXAMPLE.COM ',
            'password' => 'SecurePassword!123',
        ])->assertForbidden()
            ->assertJsonPath('code', 'email_unverified')
            ->assertJsonPath('data.merchant_id', $registration->json('data.merchant_id'));
    }

    public function test_reusing_an_expired_registration_updates_the_wallet_currency(): void
    {
        Notification::fake();

        $merchant = Merchant::create($this->registrationPayload([
            'email' => 'abandoned@example.com',
            'currency' => 'NGN',
            'cf-turnstile-response' => null,
        ]));
        $merchant->forceFill(['created_at' => now()->subMinutes(16)])->save();
        $merchant->settings()->create([
            'brand_name' => $merchant->business_name,
            'webhook_secret' => 'whsec_test',
        ]);
        Wallet::create([
            'merchant_id' => $merchant->id,
            'currency' => 'NGN',
            'available_balance' => 125.50,
            'pending_balance' => 0,
        ]);

        $response = $this->postJson('/api/auth/register', $this->registrationPayload([
            'email' => 'ABANDONED@EXAMPLE.COM',
            'currency' => 'USD',
        ]));

        $response->assertCreated();
        $this->assertSame('USD', $merchant->refresh()->currency);
        $wallet = $merchant->wallets()->firstOrFail();
        $this->assertSame('USD', $wallet->currency);
        $this->assertSame('125.5000', (string) $wallet->available_balance);
    }

    public function test_an_idempotency_key_cannot_be_reused_for_different_data(): void
    {
        Notification::fake();
        $headers = ['Idempotency-Key' => 'registration-key-1'];

        $this->postJson('/api/auth/register', $this->registrationPayload(), $headers)
            ->assertCreated();

        $this->postJson('/api/auth/register', $this->registrationPayload([
            'email' => 'different@example.com',
        ]), $headers)
            ->assertConflict()
            ->assertJsonPath('message', 'This idempotency key was already used for different registration data.');

        $this->assertDatabaseCount('merchants', 1);
    }

    public function test_repeating_the_same_idempotency_request_returns_the_original_result(): void
    {
        Notification::fake();
        $headers = ['Idempotency-Key' => 'registration-key-2'];
        $payload = $this->registrationPayload();

        $first = $this->postJson('/api/auth/register', $payload, $headers)
            ->assertCreated();
        $second = $this->postJson('/api/auth/register', $payload, $headers)
            ->assertCreated();

        $this->assertSame(
            $first->json('data.merchant_id'),
            $second->json('data.merchant_id')
        );
        $this->assertDatabaseCount('merchants', 1);
    }

    public function test_verified_owner_can_log_in_with_uppercase_email(): void
    {
        Notification::fake();

        $registration = $this->postJson('/api/auth/register', $this->registrationPayload());
        Merchant::findOrFail($registration->json('data.merchant_id'))
            ->forceFill(['email_verified_at' => now()])
            ->save();

        $this->postJson('/api/auth/login', [
            'email' => 'Owner@Example.com',
            'password' => 'SecurePassword!123',
        ])->assertStatus(202)
            ->assertJsonPath('code', 'device_unknown');
    }

    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Example Payments',
            'email' => 'owner@example.com',
            'phone' => '+2348012345678',
            'country' => 'NG',
            'currency' => 'NGN',
            'business_type' => 'Individual',
            'password' => 'SecurePassword!123',
            'password_confirmation' => 'SecurePassword!123',
            'cf-turnstile-response' => 'test-token',
        ], $overrides);
    }
}
