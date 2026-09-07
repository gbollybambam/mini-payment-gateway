<?php

namespace App\Services\PaymentProviders\Cray;

use App\Enums\PaymentStatus;
use App\Models\Transaction;
use App\Services\PaymentProviders\Contracts\PaymentProviderInterface;
use Illuminate\Support\Facades\Http;
use Override;

class CrayProvider implements PaymentProviderInterface
{
    private string $baseUrl;
    private string $secretKey;

    public function __construct()
    {
        // In a real scenario, these would pull from config('services.cray.base_url')
        $this->baseUrl = config('services.cray.base_url', 'https://api.cray.example.com');
        $this->secretKey = config('services.cray.secret', 'mock_secret_key');
    }

    public function initializePayment(Transaction $transaction): array
    {
        // Mocking the HTTP request to Cray's API
        /*
        $response = Http::withToken($this->secretKey)->post("{$this->baseUrl}/payments", [
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'reference' => $transaction->reference,
            'customer_email' => $transaction->customer_email,
        ]);
        */

        // Returning a mock successful response structure for the MVP
        return [
            'success' => true,
            'provider_reference' => 'cray_ref_' . uniqid(),
            'checkout_url' => 'https://checkout.cray.example.com/pay/' . uniqid(),
        ];
    }


    public function verifyPayment(string $providerReference): array
    {
        // Mock verification
        return [
            'success' => true,
            'status' => PaymentStatus::SUCCESS->value,
            'amount' => 5000,
            'currency' => 'NGN',
            'fee' => 50,
        ];
    }

    public function processWebhook(array $payload, string $signature): array
    {
        // Webhook signature verification logic goes here
        return [
            'is_valid' => true,
            'event' => 'payment.success',
            'provider_reference' => $payload['data']['reference'] ?? '',
        ];
    }

    public function getPaymentStatus(string $providerReference): string
    {
        return PaymentStatus::SUCCESS->value;
    }

    public function refundPayment(Transaction $transaction, float $amount): array
    {
        return ['success' => true, 'refund_reference' => 'ref_123'];
    }

    public function createPayout(array $bankDetails, float $amount, string $currency): array
    {
        return ['success' => true, 'payout_reference' => 'pout_123'];
    }
}