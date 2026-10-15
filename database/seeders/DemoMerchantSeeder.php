<?php

namespace Database\Seeders;

use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\MerchantSetting;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class DemoMerchantSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('DEMO_MERCHANT_EMAIL');
        $password = env('DEMO_MERCHANT_PASSWORD');
        $teamPassword = env('DEMO_TEAM_PASSWORD', $password);

        if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || !is_string($password) || strlen($password) < 12
            || !is_string($teamPassword) || strlen($teamPassword) < 12) {
            throw new RuntimeException(
                'Set DEMO_MERCHANT_EMAIL, DEMO_MERCHANT_PASSWORD, and optionally DEMO_TEAM_PASSWORD before seeding.'
            );
        }

        $merchant = Merchant::updateOrCreate(
            ['email' => $email],
            [
                'business_name' => 'Demo Gateway Merchant',
                'phone' => '+2348000000000',
                'country' => 'NG',
                'currency' => 'NGN',
                'business_type' => 'Technology',
                'password' => Hash::make($password),
                'email_verified_at' => now(),
                'status' => 'active',
                'is_active' => true,
            ]
        );

        MerchantSetting::updateOrCreate(
            ['merchant_id' => $merchant->id],
            [
                'brand_name' => 'Demo Gateway Merchant',
                'success_url' => env('FRONTEND_URL', 'http://localhost:3000') . '/payment-success',
                'cancel_url' => env('FRONTEND_URL', 'http://localhost:3000') . '/payment-cancelled',
                'webhook_secret' => 'whsec_' . Str::random(32),
            ]
        );

        Wallet::updateOrCreate(
            ['merchant_id' => $merchant->id, 'currency' => 'NGN'],
            ['available_balance' => 500000, 'pending_balance' => 0]
        );

        foreach ([
            ['name' => 'Demo Admin', 'email' => $this->alias($email, 'admin'), 'role' => 'admin'],
            ['name' => 'Demo Finance', 'email' => $this->alias($email, 'finance'), 'role' => 'finance'],
        ] as $member) {
            MerchantUser::updateOrCreate(
                ['merchant_id' => $merchant->id, 'email' => $member['email']],
                [
                    'name' => $member['name'],
                    'password' => Hash::make($teamPassword),
                    'role' => $member['role'],
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );
        }
    }

    private function alias(string $email, string $role): string
    {
        [$local, $domain] = explode('@', $email, 2);

        return $local . '+' . $role . '@' . $domain;
    }
}
