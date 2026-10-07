<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TeamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $currentUser = $request->user();
        $merchant = $currentUser->merchant ?? $currentUser;

        $query = MerchantUser::where('merchant_id', $merchant->id);

        // If the logged-in user is a team member (Admin role), hide themselves AND other Admins
        if ($currentUser instanceof MerchantUser) {
            $query->where('id', '!=', $currentUser->id)
                ->where('role', '!=', 'admin');
        }

        $teamMembers = $query->latest()
            ->get()
            ->map(function ($user) {
                $status = 'active';
                if (is_null($user->email_verified_at)) {
                    $status = 'pending';
                } elseif (!$user->is_active) {
                    $status = 'suspended';
                }

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'status' => $status,
                    'is_active' => $user->is_active,
                    'created_at' => $user->created_at?->toIso8601String(),
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => $teamMembers,
        ], Response::HTTP_OK);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'string', 'in:admin,developer,finance'],
        ]);

        $owner = $request->user();
        $merchant = $owner->merchant ?? $owner;

        $isPrimaryMerchant = Merchant::where('email', $request->email)->exists();
        $isAdmin = class_exists(\App\Models\Admin::class) && \App\Models\Admin::where('email', $request->email)->exists();
        $isExistingTeamMember = MerchantUser::where('email', $request->email)->exists();

        if ($isPrimaryMerchant || $isAdmin || $isExistingTeamMember) {
            return response()->json([
                'status' => 'error',
                'message' => 'This email address is unavailable for team invitations. Please use an alternative address or an email alias.'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $teamUser = MerchantUser::create([
            'merchant_id' => $merchant->id,
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make(Str::random(32)),
            'role' => $request->role,
            'is_active' => true,
            'email_verified_at' => null,
        ]);

        $inviteToken = Str::random(64);
        Cache::put('team_invite_' . $inviteToken, $teamUser->email, now()->addHours(24));

        $teamUser->notify(new \App\Notifications\Auth\SendTeamInvitationNotification($inviteToken, $merchant->business_name));

        return response()->json([
            'status' => 'success',
            'message' => 'Invitation email dispatched successfully.',
        ], Response::HTTP_CREATED);
    }

    public function resendInvite(Request $request, string $id): JsonResponse
    {
        $merchant = $request->user()->merchant ?? $request->user();

        $teamUser = MerchantUser::where('merchant_id', $merchant->id)
            ->where('id', $id)
            ->whereNull('email_verified_at')
            ->firstOrFail();

        $inviteToken = Str::random(64);
        Cache::put('team_invite_' . $inviteToken, $teamUser->email, now()->addHours(24));

        $teamUser->notify(new \App\Notifications\Auth\SendTeamInvitationNotification($inviteToken, $merchant->business_name));

        return response()->json([
            'status' => 'success',
            'message' => 'Invitation email resent successfully.',
        ], Response::HTTP_OK);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'role' => ['sometimes', 'string', 'in:admin,developer,finance'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $currentUser = $request->user();
        $merchant = $this->getMerchantContext($request);

        $teamUser = MerchantUser::where('merchant_id', $merchant->id)
            ->where('id', $id)
            ->firstOrFail();

        // 1. Prevent modifying the merchant owner
        if ($teamUser->role === 'owner') {
            return response()->json(['status' => 'error', 'message' => 'Cannot modify owner.'], Response::HTTP_FORBIDDEN);
        }

        // 2. Prevent a user from modifying themselves
        if ($currentUser->id === $teamUser->id) {
            return response()->json(['status' => 'error', 'message' => 'You cannot modify your own team account settings here.'], Response::HTTP_FORBIDDEN);
        }

        // 3. If the logged-in user is a team member (and not the primary merchant owner), 
        // they cannot edit any admin users, and can only manage developer/finance accounts.
        if ($currentUser instanceof MerchantUser) {
            if ($teamUser->role === 'admin') {
                return response()->json(['status' => 'error', 'message' => 'Team members cannot modify admin accounts.'], Response::HTTP_FORBIDDEN);
            }
        }

        $teamUser->update($request->only(['role', 'is_active']));

        if ($request->has('is_active') && $request->is_active === false) {
            $teamUser->tokens()->delete();
        }

        return response()->json(['status' => 'success', 'message' => 'Updated successfully.'], Response::HTTP_OK);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $currentUser = $request->user();
        $merchant = $currentUser->merchant ?? $currentUser;

        $teamUser = MerchantUser::where('merchant_id', $merchant->id)
            ->where('id', $id)
            ->firstOrFail();

        if ($teamUser->role === 'owner') {
            return response()->json(['status' => 'error', 'message' => 'Cannot delete owner.'], Response::HTTP_FORBIDDEN);
        }

        if ($currentUser->id === $teamUser->id) {
            return response()->json(['status' => 'error', 'message' => 'You cannot delete your own account.'], Response::HTTP_FORBIDDEN);
        }

        if ($currentUser instanceof MerchantUser) {
            if ($teamUser->role === 'admin') {
                return response()->json(['status' => 'error', 'message' => 'Team members cannot delete admin accounts.'], Response::HTTP_FORBIDDEN);
            }
        }

        $teamUser->delete();

        return response()->json(['status' => 'success', 'message' => 'Removed successfully.'], Response::HTTP_OK);
    }
}
