<?php

namespace App\Services\PaymentProviders\Cray;

use App\Enums\PaymentStatus;
use App\Models\Transaction;
use App\Models\Withdrawal;
use App\Models\Provider;
use App\Services\PaymentProviders\Contracts\PaymentProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

class CrayProvider implements PaymentProviderInterface
{
    private string $baseUrl;
    private string $combinedApiKey;
    private string $secretKey;
    private string $defaultSubaccountToken;

    public function __construct()
    {
        // Load dynamically from the database instead of .env
        $provider = Provider::where('name', 'Cray')->first();
        $config = $provider->configuration ?? [];

        $this->secretKey = $config['secret_key'] ?? '';
        $publicKey = $config['public_key'] ?? '';
        $environment = $config['environment'] ?? 'sandbox';
        $this->defaultSubaccountToken = $config['default_subaccount_token'] ?? 'default_token';

        $this->baseUrl = $environment === 'live'
            ? 'https://pay.connectramp.com'
            : 'https://dev-gateman.v3.connectramp.com';

        $this->combinedApiKey = base64_encode("{$publicKey};{$this->secretKey}");
    }

    private function client()
    {
        return Http::withoutVerifying()
            ->withHeaders([
                'x-api-key' => $this->combinedApiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->withUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    }

    public function initializePayment(Transaction $transaction): array
    {
        $merchant = $transaction->merchant;
        $subaccountToken = $merchant->settings?->cray_subaccount_token;

        if (!$subaccountToken) {
            try {
                $response = $this->client()->get("{$this->baseUrl}/api/get-subaccount");
                if ($response->successful() && $response->json('status')) {
                    $subaccounts = $response->json('data');
                    $subaccountToken = $subaccounts[0]['subaccount_token'] ?? null;
                    if ($subaccountToken) {
                        $merchant->settings()->updateOrCreate(
                            ['merchant_id' => $merchant->id],
                            ['cray_subaccount_token' => $subaccountToken]
                        );
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Failed fetching subaccount dynamically.', ['error' => $e->getMessage()]);
            }
        }

        $subaccountToken = $subaccountToken ?? $this->defaultSubaccountToken;

        if (!$subaccountToken) {
            throw new \Exception('Merchant is missing a valid payment provider subaccount token.');
        }

        $payload = [
            'amount' => (float) $transaction->amount,
            'currency' => $transaction->currency,
            'token' => $subaccountToken,
            'reference' => $transaction->reference,
            'defaultPaymentMethod' => 'card',
            'feeBearer' => 'merchant',
            'narration' => "Payment for reference {$transaction->reference}",
            'paymentMethods' => ['card', 'transfer'],
            'customer' => [
                'name' => $transaction->customer_name ?? 'Valued Customer',
                'email' => $transaction->customer_email,
            ],
            'metadata' => $transaction->metadata ?? [],
        ];

        if (isset($transaction->metadata['callback_url'])) {
            $payload['callback_url'] = $transaction->metadata['callback_url'];
        }

        $response = $this->client()->post("{$this->baseUrl}/api/checkout/initialize", $payload);

        if (!$response->successful() || !($response->json('status') ?? false)) {
            $rawBody = $response->body();
            Log::error('Cray Live Initialize Payment Failed', [
                'status_code' => $response->status(),
                'response_body' => $rawBody
            ]);
            throw new \Exception('Cray Error (Status ' . $response->status() . '): ' . $rawBody);
        }

        $data = $response->json('data');

        return [
            'success' => true,
            'provider_reference' => $data['payCode'] ?? $transaction->reference,
            'checkout_url' => $data['link'],
        ];
    }

    public function verifyPayment(string $providerReference): array
    {
        $response = $this->client()->get("{$this->baseUrl}/api/checkout/query/{$providerReference}");

        if (!$response->successful() || !($response->json('status') ?? false)) {
            throw new \Exception('Failed to verify payment with live provider.');
        }

        $data = $response->json('data');
        $isSuccessful = strtolower($data['status'] ?? '') === 'successful';

        return [
            'success' => $isSuccessful,
            'status' => $isSuccessful ? PaymentStatus::SUCCESS->value : PaymentStatus::FAILED->value,
            'amount' => $data['amount'] ?? 0,
            'net_amount' => $data['net_amount'] ?? 0,
            'fee' => $data['fee'] ?? 0,
        ];
    }

    public function processWebhook(Request $request): array
    {
        $payload = $request->all();
        $receivedHash = $payload['hash'] ?? '';
        $dataObject = $payload['data'] ?? [];

        // Corrected: Uses the database-loaded secret key
        $expectedHash = base64_encode(
            hash_hmac('sha256', json_encode($dataObject), $this->secretKey, true)
        );

        $isValid = hash_equals($expectedHash, $receivedHash);
        $event = $payload['event'] ?? '';

        $normalizedEvent = match ($event) {
            'collection.mobile_money.successful', 'payin', 'transaction_complete' => 'payment.success',
            'collection.mobile_money.failed' => 'payment.failed',
            'payout' => ($dataObject['status'] ?? '') === 'Successful' ? 'withdrawal.success' : 'withdrawal.failed',
            default => $event,
        };

        return [
            'is_valid' => $isValid,
            'event' => $normalizedEvent,
            'provider_reference' => $dataObject['reference'] ?? '',
            'amount' => $dataObject['amount'] ?? null,
            'fee' => $dataObject['charge'] ?? $dataObject['fee'] ?? 0,
            'reason' => $dataObject['failure_reason'] ?? null,
        ];
    }

    public function getPaymentStatus(string $providerReference): string
    {
        $result = $this->verifyPayment($providerReference);
        return $result['status'];
    }

    public function refundPayment(Transaction $transaction, float $amount): array
    {
        return ['success' => true, 'refund_reference' => 'ref_mock'];
    }

    public function initializePayout(Withdrawal $withdrawal): array
    {
        return ['success' => true, 'provider_reference' => $withdrawal->reference, 'status' => 'PROCESSING', 'fee' => 50.00];
    }

    public function verifyPayout(string $providerReference): array
    {
        return ['success' => true, 'status' => 'SUCCESSFUL', 'amount' => 0, 'fee' => 0];
    }

    public function healthCheck(): bool
    {
        try {
            return $this->client()->get("{$this->baseUrl}/api/get-subaccount")->successful();
        } catch (\Exception $e) {
            return false;
        }
    }
}
