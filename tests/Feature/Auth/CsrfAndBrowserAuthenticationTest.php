<?php

namespace Tests\Feature\Auth;

use App\Models\Merchant;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Tests\TestCase;

class CsrfAndBrowserAuthenticationTest extends TestCase
{
    public function test_state_changing_request_without_csrf_header_is_rejected(): void
    {
        $response = $this->get('/sanctum/csrf-cookie')->assertNoContent();
        $this->assertNotNull($response->getCookie('XSRF-TOKEN'));

        $request = Request::create('/api/auth/login', 'POST', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:3000',
        ]);
        $session = app('session')->driver();
        $session->start();
        $request->setLaravelSession($session);

        $middleware = new class ($this->app, app('encrypter')) extends PreventRequestForgery {
            protected function runningUnitTests()
            {
                return false;
            }
        };

        $this->expectException(TokenMismatchException::class);
        $middleware->handle($request, fn () => response()->noContent());
    }

    public function test_authenticated_mutations_from_an_untrusted_origin_are_rejected(): void
    {
        $merchant = $this->merchant();
        $this->actingAs($merchant, 'web');

        foreach ([
            fn () => $this->withHeader('Origin', 'https://attacker.example')
                ->postJson('/api/payment-links', []),
            fn () => $this->withHeader('Origin', 'https://attacker.example')
                ->patchJson('/api/settings', []),
            fn () => $this->withHeader('Origin', 'https://attacker.example')
                ->deleteJson('/api/api-keys/not-a-real-key'),
        ] as $request) {
            $request()->assertForbidden()
                ->assertJsonPath('message', 'Untrusted request origin.');
        }
    }

    public function test_login_csrf_from_an_untrusted_origin_is_rejected(): void
    {
        $this->withHeader('Origin', 'https://attacker.example')
            ->postJson('/api/auth/login', [
                'email' => 'owner@example.com',
                'password' => 'password',
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Untrusted request origin.');
    }

    private function merchant(): Merchant
    {
        return new Merchant([
            'business_name' => 'Example Payments',
            'email' => 'owner@example.com',
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
