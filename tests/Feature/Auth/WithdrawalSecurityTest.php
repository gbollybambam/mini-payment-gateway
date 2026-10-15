<?php

namespace Tests\Feature\Auth;

use App\Enums\WithdrawalStatus;
use App\Models\Merchant;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\PaymentProviders\Contracts\PaymentProviderInterface;
use App\Services\PaymentProviders\ProviderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class WithdrawalSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_withdrawal_inputs_are_rejected(): void
    {
        $merchant = $this->merchant(500);
        $this->actingAs($merchant, 'web');
        $token = $this->apiKey();

        $this->withToken($token)->postJson('/api/v1/withdrawals', $this->payload([
            'amount' => '100.001',
            'account_number' => '12abc',
        ]))->assertUnprocessable();
    }

    public function test_provider_failure_reverses_the_debit_once(): void
    {
        $merchant = $this->merchant(500);
        $this->actingAs($merchant, 'web');
        $token = $this->apiKey();

        $provider = Mockery::mock(PaymentProviderInterface::class);
        $provider->shouldReceive('initializePayout')->once()->andThrow(new \RuntimeException('provider secret'));
        $factory = Mockery::mock(ProviderFactory::class);
        $factory->shouldReceive('getActiveProviderName')->once()->andReturn('paystack');
        $factory->shouldReceive('make')->once()->with('paystack')->andReturn($provider);
        $this->app->instance(ProviderFactory::class, $factory);

        $this->withToken($token)->postJson('/api/v1/withdrawals', $this->payload())
            ->assertStatus(502)
            ->assertJsonPath('message', 'Payout initialization failed. Funds have been reversed to your wallet.');

        $this->assertDatabaseHas('withdrawals', ['reference' => 'withdrawal-' . $this->testRunId, 'status' => WithdrawalStatus::FAILED->value]);
        $this->assertDatabaseCount('ledger_entries', 2);
        $this->assertDatabaseHas('wallets', ['merchant_id' => $merchant->id, 'available_balance' => 500]);
        $this->assertDatabaseMissing('ledger_entries', ['reference' => 'ldg_rev_withdrawal-' . $this->testRunId . '-duplicate']);
    }

    public function test_cray_payout_cannot_be_reported_as_successful(): void
    {
        $this->expectException(\RuntimeException::class);
        $provider = app(\App\Services\PaymentProviders\Cray\CrayProvider::class);
        $provider->initializePayout(new Withdrawal([
            'amount' => 100,
            'currency' => 'NGN',
            'reference' => 'cray-test',
        ]));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function apiKey(): string
    {
        return $this->postJson('/api/api-keys', [
            'name' => 'Withdrawal security key',
            'environment' => 'test',
            'scopes' => ['withdrawals:write'],
        ])->assertCreated()->json('data.plain_text_key');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'amount' => 100,
            'currency' => 'NGN',
            'reference' => 'withdrawal-' . $this->testRunId,
            'bank_code' => '058',
            'account_number' => '0123456789',
            'account_name' => 'Test Recipient',
        ], $overrides);
    }

    private function merchant(float $balance): Merchant
    {
        $merchant = Merchant::create([
            'business_name' => 'Withdrawal Merchant',
            'email' => 'withdrawal-' . uniqid() . '@example.com',
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
            'available_balance' => $balance,
            'pending_balance' => 0,
        ]);

        return $merchant;
    }

    private string $testRunId = 'test';
}
