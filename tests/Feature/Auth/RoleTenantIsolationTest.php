<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\EnsureMerchantIsActive;
use App\Models\Admin;
use App\Models\Merchant;
use App\Models\MerchantUser;
use Illuminate\Http\Request;
use Tests\TestCase;

class RoleTenantIsolationTest extends TestCase
{
    public function test_owner_can_pass_every_role_gate(): void
    {
        $response = app(CheckRole::class)->handle(
            $this->authenticatedRequest(new Merchant),
            fn () => response()->json(['ok' => true])
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_role_gate_allows_only_the_declared_team_roles(): void
    {
        $middleware = app(CheckRole::class);

        $this->assertSame(200, $middleware->handle(
            $this->authenticatedRequest(new MerchantUser(['role' => 'admin'])),
            fn () => response()->json([], 200),
            'admin'
        )->getStatusCode());

        $this->assertSame(403, $middleware->handle(
            $this->authenticatedRequest(new MerchantUser(['role' => 'developer'])),
            fn () => response()->json([], 200),
            'admin'
        )->getStatusCode());

        $this->assertSame(403, $middleware->handle(
            $this->authenticatedRequest(new MerchantUser(['role' => 'finance'])),
            fn () => response()->json([], 200),
            'admin,developer'
        )->getStatusCode());
    }

    public function test_non_merchant_principals_cannot_pass_merchant_role_or_active_gates(): void
    {
        $request = $this->authenticatedRequest(new Admin);

        $this->assertSame(403, app(CheckRole::class)->handle(
            $request,
            fn () => response()->json([], 200),
            'admin'
        )->getStatusCode());

        $this->assertSame(403, app(EnsureMerchantIsActive::class)->handle(
            $request,
            fn () => response()->json([], 200)
        )->getStatusCode());
    }

    public function test_inactive_merchant_is_rejected_before_controller_execution(): void
    {
        $merchant = new Merchant(['is_active' => false]);
        $response = app(EnsureMerchantIsActive::class)->handle(
            $this->authenticatedRequest($merchant),
            fn () => response()->json([], 200)
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('suspended', strtolower((string) $response->getContent()));
    }

    public function test_team_member_context_cannot_escape_its_merchant(): void
    {
        $merchantA = new Merchant(['id' => 'merchant-a']);
        $merchantB = new Merchant(['id' => 'merchant-b']);
        $teamMember = new MerchantUser(['merchant_id' => $merchantA->id]);
        $teamMember->setRelation('merchant', $merchantA);

        $controller = new class extends Controller {
            public function context(Request $request): Merchant
            {
                return $this->getMerchantContext($request);
            }
        };

        $request = $this->authenticatedRequest($teamMember);
        $request->merge(['merchant' => $merchantB]);

        $this->assertSame($merchantA->id, $controller->context($request)->id);
    }

    private function authenticatedRequest(object $user): Request
    {
        $request = Request::create('/api/test', 'GET');
        $request->setUserResolver(fn () => $user);

        return $request;
    }
}
