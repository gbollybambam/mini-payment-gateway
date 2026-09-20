<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Provider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        DB::transaction(function () use ($provider, $validated) {
            // Enforce Single Active Provider Rule
            if (isset($validated['is_active']) && $validated['is_active'] === true) {
                Provider::where('id', '!=', $provider->id)->update(['is_active' => false]);
            }

            $provider->update($validated);
        });

        // PRD Section 17: Log this action
        AuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'UPDATE_PROVIDER',
            'target_type' => Provider::class,
            'target_id' => $provider->id,
            'changes' => $validated,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['status' => 'success', 'data' => $provider->fresh()], Response::HTTP_OK);
    }
}
