<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // 1. Setup Default Admin
        \App\Models\Admin::updateOrCreate(
            ['email' => 'admin@gateway.local'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('SecureAdmin123!'),
            ]
        );

        // 2. Setup Paystack (Active) - Pulls keys directly from .env
        \App\Models\Provider::updateOrCreate(
            ['name' => 'Paystack'],
            [
                'is_active' => true,
                'configuration' => [
                    'public_key' => env('PAYSTACK_PUBLIC_KEY', 'pk_test_placeholder'),
                    'secret_key' => env('PAYSTACK_SECRET_KEY', 'sk_test_placeholder'),
                ],
            ]
        );

        // 3. Setup Cray (Inactive) - Pulls keys directly from .env
        \App\Models\Provider::updateOrCreate(
            ['name' => 'Cray'],
            [
                'is_active' => false,
                'configuration' => [
                    'public_key' => env('CRAY_PUBLIC_KEY', 'cray_pk_test_placeholder'),
                    'secret_key' => env('CRAY_SECRET_KEY', 'cray_sk_test_placeholder'),
                    'environment' => 'sandbox',
                    'default_subaccount_token' => 'default_token_placeholder'
                ],
            ]
        );
    }
}
