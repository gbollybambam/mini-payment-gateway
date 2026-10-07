<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Merchant;
use App\Models\MerchantUser;

abstract class Controller
{
    /**
     * Globally resolve the true Merchant context, regardless of who is driving the session.
     */
    protected function getMerchantContext(Request $request): Merchant
    {
        // 1. If hitting via an API Key, the VerifyApiKey middleware already set $request->merchant
        if ($request->merchant) {
            return $request->merchant;
        }

        $user = $request->user();

        // 2. If a Team Member is logged in, their ID is useless for queries. We need their Boss's ID.
        if ($user instanceof MerchantUser) {
            return $user->merchant;
        }

        // 3. Otherwise, it is the Primary Merchant Owner
        return $user;
    }
}
