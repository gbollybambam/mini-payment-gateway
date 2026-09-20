<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

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
                'password' => \Illuminate\Support\Facades\Hash::make('SecureAdmin123!'),
            ]
        );

        // 2. Setup Paystack (Active)
        \App\Models\Provider::updateOrCreate(
            ['name' => 'Paystack'],
            [
                'is_active' => true,
                'configuration' => [
                    'public_key' => 'pk_test_placeholder', // Update via Admin UI
                    'secret_key' => 'sk_test_placeholder', // Update via Admin UI
                ],
            ]
        );

        // 3. Setup Cray (Inactive)
        \App\Models\Provider::updateOrCreate(
            ['name' => 'Cray'],
            [
                'is_active' => false,
                'configuration' => [
                    'public_key' => 'cray_pk_test_placeholder', // Update via Admin UI
                    'secret_key' => 'cray_sk_test_placeholder', // Update via Admin UI
                    'environment' => 'sandbox',
                    'default_subaccount_token' => 'default_token_placeholder'
                ],
            ]
        );
    }
}
