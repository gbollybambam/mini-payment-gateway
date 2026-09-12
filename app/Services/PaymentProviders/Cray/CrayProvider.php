<?php

namespace App\Services\PaymentProviders\Cray;

use App\Enums\PaymentStatus;
use App\Models\Transaction;
use App\Models\Withdrawal;
use App\Models\Merchant;
use App\Services\PaymentProviders\Contracts\PaymentProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

class CrayProvider implements PaymentProviderInterface
{
    private string $baseUrl;
    private string $secretKey;

    public function __construct()
    {
        $this->secretKey = config('services.cray.secret');
        $environment = config('services.cray.environment', 'sandbox');

        // In a real scenario, these would pull from config('services.cray.base_url')
        $this->baseUrl = $environment === 'live'
            ? 'https://pay.connectramp.com'
            : 'https://dev-gateman.v3.connectramp.com';
    }

    public function initializePayment(Transaction $transaction): array
    {
        $merchant = $transaction->merchant;

        $subaccountToken = $merchant->settings?->cray_subaccount_token
            ?? config('services.cray.default_subaccount_token');

        if (!$subaccountToken) {
            try {
                $subaccountToken = $this->createMerchantSubaccount($merchant);

                // Save it to their settings permanently so we only do this once
                $merchant->settings()->updateOrCreate(
                    ['merchant_id' => $merchant->id],
                    ['cray_subaccount_token' => $subaccountToken]
                );
            } catch (\Exception $e) {
                Log::warning('Lazy subaccount creation failed, falling back to default.', ['error' => $e->getMessage()]);
                $subaccountToken = config('services.cray.default_subaccount_token');
            }
        }

        if (!$subaccountToken) {
            throw new \Exception('Merchant is missing a valid payment provider subaccount token.');
        }

        $response = Http::withToken($this->secretKey)
            ->post("{$this->baseUrl}/api/checkout/initialize", [
                'amount' => (float) $transaction->amount,
                'currency' => $transaction->currency,
                'token' => $subaccountToken,
                'reference' => $transaction->reference,
                'defaultPaymentMethod' => 'card',
                'feeBearer' => 'merchant',
                'narration' => "Payment for transaction {$transaction->reference}",
                'paymentMethods' => ['card'],
                'customer' => [
                    'name' => $transaction->customer_name ?? 'Valued Customer',
                    'email' => $transaction->customer_email,
                ],
                'metadata' => $transaction->metadata ?? [],
            ]);

        if (!$response->successful() || !($response->json('status') ?? false)) {
            Log::error('Cray Initialize Payment Failed', ['response' => $response->body()]);
            throw new \Exception('Payment provider failed to initialize checkout: ' . $response->json('message', 'Unknown error'));
        }

        $data = $response->json('data');

        return [
            'success' => true,
            'provider_reference' => $data['reference'] ?? $transaction->reference,
            'checkout_url' => $data['link'],
        ];
    }


    public function verifyPayment(string $providerReference): array
    {
        $response = Http::withToken($this->secretKey)
            ->get("{$this->baseUrl}/api/checkout/query/{$providerReference}");

        if (!$response->successful() || !($response->json('status') ?? false)) {
            throw new \Exception('Failed to verify payment with provider.');
        }

        $data = $response->json('data');

        // Normalize Cray's status ("Successful") into our gateway enum value
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

        // Cray specific: signature hash is inside the JSON body
        $receivedHash = $payload['hash'] ?? '';
        $dataObject = $payload['data'] ?? [];

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
        $merchant = $transaction->merchant;
        $subaccountToken = $merchant->settings?->cray_subaccount_token ?? config('services.cray.default_subaccount_token');

        $response = Http::withToken($this->secretKey)
            ->post("{$this->baseUrl}/refund/initiate", [
                'reference' => 'ref_' . \Illuminate\Support\Str::random(16),
                'subaccount_id' => $subaccountToken,
                'amount' => (string) $amount,
                'pan' => $transaction->metadata['pan'] ?? '0000000000000000', // fallback identifier
            ]);

        if (!$response->successful()) {
            throw new \Exception('Failed to process refund with provider: ' . $response->json('message', 'Unknown error'));
        }

        return [
            'success' => true,
            'refund_reference' => $response->json('data.reference', 'ref_unknown'),
        ];
    }

    public function initializePayout(Withdrawal $withdrawal): array
    {
        $merchant = $withdrawal->merchant;
        $bankAccount = $withdrawal->bank_account;

        // Step 1: Validate Beneficiary Account per Cray Payouts docs
        $validationResponse = Http::withToken($this->secretKey)
            ->post("{$this->baseUrl}/payout/accounts/validate", [
                'account_number' => $bankAccount['account_number'],
                'bank_code' => $bankAccount['bank_code'],
            ]);

        if (!$validationResponse->successful() || !($validationResponse->json('success') ?? false)) {
            throw new \Exception('Failed to validate beneficiary account: ' . $validationResponse->json('message', 'Validation failed'));
        }

        $refId = $validationResponse->json('data.ref_id');

        // Step 2: Initiate Disbursement
        $disburseResponse = Http::withToken($this->secretKey)
            ->post("{$this->baseUrl}/payout/disburse", [
                'customer_reference' => $withdrawal->reference,
                'amount' => (float) $withdrawal->amount,
                'currency' => $withdrawal->currency,
                'narration' => "Withdrawal {$withdrawal->reference}",
                'ref_id' => $refId,
                'account_number' => $bankAccount['account_number'],
                'receipient_info' => [
                    'account_number' => $bankAccount['account_number'],
                    'bank_code' => $bankAccount['bank_code'],
                    'country' => $bankAccount['country'] ?? 'nigeria', // Default or mapped country
                ]
            ]);

        if (!$disburseResponse->successful() || !($disburseResponse->json('success') ?? false)) {
            throw new \Exception('Payout disbursement failed: ' . $disburseResponse->json('message', 'Unknown error'));
        }

        $data = $disburseResponse->json('data');

        return [
            'success' => true,
            'provider_reference' => $data['reference'] ?? $withdrawal->reference,
            'status' => strtoupper($data['status'] ?? 'PROCESSING'),
            'fee' => $data['charge'] ?? 50.00,
        ];
    }

    public function verifyPayout(string $providerReference): array
    {
        $response = Http::withToken($this->secretKey)
            ->get("{$this->baseUrl}/payout/requery/{$providerReference}");

        if (!$response->successful()) {
            throw new \Exception('Failed to verify payout status with provider.');
        }

        $data = $response->json('data');
        $status = strtoupper($data['status'] ?? 'PENDING');

        return [
            'success' => $status === 'SUCCESSFUL',
            'status' => $status,
            'amount' => $data['amount'] ?? null,
            'fee' => $data['charge'] ?? 0,
        ];
    }

    public function healthCheck(): bool
    {
        try {
            // Ping Cray's subaccount endpoint using our secret key to verify connectivity
            $response = Http::withToken($this->secretKey)
                ->timeout(5)
                ->get("{$this->baseUrl}/api/get-subaccount"); // <--- Fixed to ->get()

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function createMerchantSubaccount(Merchant $merchant): string
    {
        $response = Http::withToken($this->secretKey)
            ->post("{$this->baseUrl}/api/create-subaccount", [
                'company_name' => $merchant->business_name,
                'company_email' => $merchant->email,
            ]);

        if (!$response->successful()) {
            throw new \Exception('Failed to create Cray subaccount.');
        }

        return $response->json('data.subaccount_token');
    }
}
