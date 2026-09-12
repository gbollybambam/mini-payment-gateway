<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Provider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProviderController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => Provider::all()], Response::HTTP_OK);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'is_active' => 'sometimes|boolean',
            'configuration' => 'sometimes|array',
        ]);

        $provider = Provider::findOrFail($id);
        $provider->update($validated);

        // PRD Section 17: Log this action
        AuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'UPDATE_PROVIDER',
            'target_type' => Provider::class,
            'target_id' => $provider->id,
            'changes' => $validated,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['status' => 'success', 'data' => $provider], Response::HTTP_OK);
    }
}
