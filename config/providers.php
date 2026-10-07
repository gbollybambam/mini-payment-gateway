<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Active Payment Providers
    |--------------------------------------------------------------------------
    | List all provider classes that implement PaymentProviderInterface.
    | These will be automatically provisioned when a merchant verifies their email.
    */
    'active' => [
        \App\Services\PaymentProviders\Paystack\PaystackProvider::class,
    ],
];
