<?php

namespace Tests\Feature\Auth;

use App\Models\ApiKey;
use App\Models\Merchant;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiKeySecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_and_test_keys_use_the_correct_prefix_and_only_creation_returns_plaintext(): void
    {
        $merchant = $this->merchant('owner@example.com');
        $this->actingAs($merchant, 'web');

        $live = $this->postJson('/api/api-keys', [
            'name' => 'Live key',
            'environment' => 'live',
            'scopes' => ['payments:read'],
        ])->assertCreated();

        $this->assertStringStartsWith('sk_live_', $live->json('data.plain_text_key'));
        $this->assertNotNull($live->json('data.plain_text_key'));

        $this->getJson('/api/api-keys')
            ->assertOk()
            ->assertJsonMissingPath('data.0.plain_text_key');

        $this->assertStringNotContainsString(
            $live->json('data.plain_text_key'),
            $this->getJson('/api/api-keys')->getContent()
        );
    }

    public function test_unknown_or_wildcard_scopes_cannot_be_requested(): void
    {
        $this->actingAs($this->merchant('owner@example.com'), 'web');

        $this->postJson('/api/api-keys', [
            'name' => 'Escalation key',
            'environment' => 'test',
            'scopes' => ['*'],
        ])->assertUnprocessable();
    }

    public function test_a_read_key_cannot_write_and_a_write_key_cannot_read(): void
    {
        $merchant = $this->merchant('owner@example.com');
        $this->actingAs($merchant, 'web');

        $read = $this->createKey(['payments:read']);
        $this->withToken($read)->getJson('/api/v1/payments')->assertOk();
        $this->withToken($read)->postJson('/api/v1/payments', [])->assertForbidden();

        $write = $this->createKey(['payments:write']);
        $this->withToken($write)->postJson('/api/v1/payments', [])->assertUnprocessable();
        $this->withToken($write)->getJson('/api/v1/payments')->assertForbidden();
    }

    public function test_revoked_expired_and_rotated_secrets_stop_working(): void
    {
        $merchant = $this->merchant('owner@example.com');
        $this->actingAs($merchant, 'web');

        $key = $this->createKey(['payments:read']);
        $apiKey = ApiKey::where('merchant_id', $merchant->id)->firstOrFail();

        $this->withToken($key)->getJson('/api/v1/payments')->assertOk();
        $apiKey->update(['revoked_at' => now()]);
        $this->withToken($key)->getJson('/api/v1/payments')->assertUnauthorized();

        $apiKey->update(['revoked_at' => null, 'expires_at' => now()->subSecond()]);
        $this->withToken($key)->getJson('/api/v1/payments')->assertUnauthorized();

        $apiKey->update(['expires_at' => null]);
        $oldKey = $key;
        $newKey = $this->postJson("/api/api-keys/{$apiKey->id}/rotate")
            ->assertOk()
            ->json('data.plain_text_key');
        $this->withToken($oldKey)->getJson('/api/v1/payments')->assertUnauthorized();
        $this->withToken($newKey)->getJson('/api/v1/payments')->assertOk();
    }

    public function test_api_keys_are_scoped_to_their_merchant(): void
    {
        $merchantA = $this->merchant('a@example.com');
        $merchantB = $this->merchant('b@example.com');
        Transaction::create([
            'merchant_id' => $merchantB->id,
            'provider' => 'test',
            'reference' => 'txn-b',
            'amount' => 100,
            'currency' => 'NGN',
            'status' => 'PENDING',
        ]);

        $this->actingAs($merchantA, 'web');
        $key = $this->createKey(['payments:read']);
        $transaction = Transaction::where('merchant_id', $merchantB->id)->firstOrFail();

        $this->withToken($key)->getJson("/api/v1/payments/{$transaction->id}")
            ->assertNotFound();
    }

    private function createKey(array $scopes): string
    {
        return $this->postJson('/api/api-keys', [
            'name' => 'Test key',
            'environment' => 'test',
            'scopes' => $scopes,
        ])->assertCreated()->json('data.plain_text_key');
    }

    private function merchant(string $email): Merchant
    {
        return Merchant::create([
            'business_name' => 'Example Payments',
            'email' => $email,
            'phone' => '+2348012345678',
            'country' => 'NG',
            'currency' => 'NGN',
            'business_type' => 'Individual',
            'password' => 'SecurePassword!123',
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
    }
}
