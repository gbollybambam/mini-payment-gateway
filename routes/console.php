<?php

use App\Models\Merchant;
use Illuminate\Support\Facades\Schedule;

// Automatically destroy unverified accounts and their cascading wallets/settings after 48 hours
Schedule::call(function () {
    Merchant::whereNull('email_verified_at')
        ->where('created_at', '<', now()->subHours(48))
        ->delete();
})->hourly();