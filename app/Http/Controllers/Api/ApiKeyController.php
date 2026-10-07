<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApiKey\StoreApiKeyRequest;
use App\Http\Resources\ApiKeyResource;
use App\Services\ApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Carbon;

class ApiKeyController extends Controller
{
    public function __construct(private ApiKeyService $apiKeyService) {}

    public function index(Request $request): JsonResponse
    {
        $merchant = $this->getMerchantContext($request);
        $keys = $merchant->apiKeys()->orderByDesc('created_at')->get();

        return response()->json([
            'status' => 'success',
            'data' => ApiKeyResource::collection($keys),
        ], Response::HTTP_OK);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'environment' => ['required', 'in:live,test'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string'], // You can restrict this later using an 'in:' rule
            'expiration_days' => ['nullable', 'integer', 'min:1', 'max:365'], // null = never expires
        ]);

        $merchant = $this->getMerchantContext($request);

        $result = $this->apiKeyService->generate(
            $merchant,
            $request->name,
            $request->environment
        );

        $apiKey = $result['api_key'];

        // Apply the granular settings
        $apiKey->scopes = $request->scopes;

        if ($request->expiration_days) {
            $apiKey->expires_at = now()->addDays($request->expiration_days);
        }

        $apiKey->save();

        $apiKey->plain_text_key = $result['plain_text_key'];

        return response()->json([
            'status' => 'success',
            'message' => 'API key generated. Store this secret key now, as it will not be displayed again.',
            'data' => new ApiKeyResource($apiKey),
        ], Response::HTTP_CREATED);
    }

    public function rotate(Request $request, string $id): JsonResponse
    {
        $merchant = $this->getMerchantContext($request);
        $apiKey = $merchant->apiKeys()->findOrFail($id);

        $plainTextKey = $this->apiKeyService->rotate($apiKey);

        // If the key had an expiration, let's reset the clock from today
        if ($apiKey->expires_at) {
            $diffInDays = $apiKey->created_at->diffInDays($apiKey->expires_at);
            $apiKey->update(['expires_at' => now()->addDays($diffInDays)]);
        }

        $apiKey->plain_text_key = $plainTextKey;

        return response()->json([
            'status' => 'success',
            'message' => 'API key rotated successfully. Store your new secret key.',
            'data' => new ApiKeyResource($apiKey),
        ], Response::HTTP_OK);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $merchant = $this->getMerchantContext($request);
        $apiKey = $merchant->apiKeys()->findOrFail($id);

        $apiKey->update(['revoked_at' => now()]);

        return response()->json([
            'status' => 'success',
            'message' => 'API key revoked successfully.',
        ], Response::HTTP_OK);
    }
}
