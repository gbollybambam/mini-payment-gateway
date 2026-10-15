<?php

namespace Tests\Feature\Auth;

use App\Support\SafeWebhookUrl;
use App\Services\Ledger\LedgerService;
use App\Services\PaymentProviders\Contracts\PaymentProviderInterface;
use App\Services\PaymentProviders\ProviderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class WebhookSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_uses_the_provider_named_by_the_route(): void
    {
        $provider = Mockery::mock(PaymentProviderInterface::class);
        $provider->shouldReceive('processWebhook')
            ->once()
            ->andReturn([
                'is_valid' => false,
                'event' => '',
                'provider_reference' => '',
            ]);

        $factory = Mockery::mock(ProviderFactory::class);
        $factory->shouldReceive('make')->once()->with('paystack')->andReturn($provider);
        $this->app->instance(ProviderFactory::class, $factory);

        $this->postJson('/api/webhooks/paystack', ['event' => 'charge.success'])
            ->assertUnauthorized();
    }

    public function test_unknown_provider_and_malformed_webhooks_are_rejected(): void
    {
        $this->postJson('/api/webhooks/unknown', [])
            ->assertBadRequest();

        $provider = Mockery::mock(PaymentProviderInterface::class);
        $provider->shouldReceive('processWebhook')->andReturn([
            'is_valid' => true,
            'event' => 'payment.success',
            'provider_reference' => '',
        ]);
        $factory = Mockery::mock(ProviderFactory::class);
        $factory->shouldReceive('make')->andReturn($provider);
        $this->app->instance(ProviderFactory::class, $factory);

        $this->postJson('/api/webhooks/cray', ['event' => 'payin'])
            ->assertUnauthorized();
    }

    public function test_private_webhook_networks_are_rejected(): void
    {
        foreach ([
            'https://169.254.169.254/hook',
            'https://192.168.1.1/hook',
            'https://10.0.0.1/hook',
            'https://172.16.0.1/hook',
            'https://127.0.0.1/hook',
            'https://[::1]/hook',
        ] as $url) {
            $this->assertFalse(SafeWebhookUrl::isSafe($url), $url);
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
