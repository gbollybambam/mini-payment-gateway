<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApiKey\StoreApiKeyRequest;
use App\Http\Resources\ApiKeyResource;
use App\Services\ApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyController extends Controller
{
    public function __construct(private ApiKeyResource $apiKeyService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $keys = $request->user()->apiKeys()->orderByDesc('created_at')->get();

        return response()->json([
            'status' => 'success',
            'data' => ApiKeyResource::collection($keys),
        ], Response::HTTP_OK);
    }

    public function store(StoreApiKeyRequest $request): JsonResponse
    {
        $result = $this->apiKeyService->generate(
            $request->user(),
            $request->validated('name'),
            $request->validated('environment')
        );

        $apiKey = $result['api_key'];
        $apiKey->plain_text_key = $result['plain_text_key'];

        return response()->json([
            'status' => 'success',
            'message' => 'API key generated. Store this secret key now, as it will nnot be displayed again.',
            'data' => new ApiKeyResource($apiKey),
        ], Response::HTTP_CREATED);
    }

    public function rotate(Request $request, string $id): JsonResponse
    {
        $apiKey = $request->user()->apiKeys()->findOrFail($id);

        $plainTextKey = $this->apiKeyService->rotate($apiKey);
        $apiKey->plain_text_key = $plainTextKey;

        return response()->json([
            'status' => 'succeess',
            'message' => 'API key rotated successfully. Store your new secret key.',
            'data' => new ApiKeyResource($apiKey),
        ], Response::HTTP_OK);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $apiKey = $request->user()->apiKeys()->findOrFail($id);

        $apiKey->update([ 'revoked' => now() ]);

        return response()->json([
            'status' => 'success',
            'message' => 'API key revoked successfully.',
        ], Response::HTTP_OK);
    }
}
