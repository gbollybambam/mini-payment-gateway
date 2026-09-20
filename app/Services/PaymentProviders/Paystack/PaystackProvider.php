<?php

namespace App\Services\PaymentProviders\Paystack;

use App\Enums\PaymentStatus;
use App\Models\Transaction;
use App\Models\Withdrawal;
use App\Models\Merchant;
use App\Models\Provider;
use App\Services\PaymentProviders\Contracts\PaymentProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

class PaystackProvider implements PaymentProviderInterface
{
    private string $secretKey;
    private string $baseUrl = 'https://api.paystack.co';

    public function __construct()
    {
        // Load dynamically from the database instead of .env
        $provider = Provider::where('name', 'Paystack')->first();
        $this->secretKey = $provider->configuration['secret_key'] ?? '';
    }

    private function client()
    {
        return Http::withToken($this->secretKey)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]);
    }

    public function initializePayment(Transaction $transaction): array
    {
        $callbackUrl = "http://localhost:3000/payment-success";

        $response = $this->client()->post("{$this->baseUrl}/transaction/initialize", [
            'email' => $transaction->customer_email,
            'amount' => (int) ($transaction->amount * 100),
            'reference' => $transaction->reference,
            'callback_url' => $callbackUrl,
            'metadata' => $transaction->metadata ?? [],
        ]);

        if (!$response->successful() || !$response->json('status')) {
            Log::error('Paystack Initialization Failed', ['response' => $response->body()]);
            throw new \Exception('Payment provider failed to initialize checkout: ' . $response->json('message'));
        }

        $data = $response->json('data');

        return [
            'success' => true,
            'provider_reference' => $data['reference'],
            'checkout_url' => $data['authorization_url'],
        ];
    }

    public function verifyPayment(string $providerReference): array
    {
        $response = $this->client()->get("{$this->baseUrl}/transaction/verify/{$providerReference}");

        if (!$response->successful() || !$response->json('status')) {
            throw new \Exception('Failed to verify payment with Paystack.');
        }

        $data = $response->json('data');
        $isSuccessful = ($data['status'] ?? '') === 'success';

        return [
            'success' => $isSuccessful,
            'status' => $isSuccessful ? PaymentStatus::SUCCESS->value : PaymentStatus::FAILED->value,
            'amount' => $data['amount'] / 100,
            'net_amount' => ($data['amount'] - ($data['fees'] ?? 0)) / 100,
            'fee' => ($data['fees'] ?? 0) / 100,
        ];
    }

    public function processWebhook(Request $request): array
    {
        $payload = $request->all();
        $event = $payload['event'] ?? '';
        $dataObject = $payload['data'] ?? [];

        $signature = $request->header('x-paystack-signature');
        $expectedSignature = hash_hmac('sha512', $request->getContent(), $this->secretKey);
        $isValid = hash_equals($expectedSignature, $signature);

        $normalizedEvent = match ($event) {
            'charge.success' => 'payment.success',
            'charge.failed' => 'payment.failed',
            'transfer.success' => 'withdrawal.success',
            'transfer.failed', 'transfer.reversed' => 'withdrawal.failed',
            default => $event,
        };

        return [
            'is_valid' => $isValid,
            'event' => $normalizedEvent,
            'provider_reference' => $dataObject['reference'] ?? $dataObject['transfer_code'] ?? '',
            'amount' => ($dataObject['amount'] ?? 0) / 100,
            'fee' => ($dataObject['fees'] ?? 0) / 100,
            'reason' => $dataObject['gateway_response'] ?? $dataObject['failures'] ?? null,
        ];
    }

    public function getPaymentStatus(string $providerReference): string
    {
        $result = $this->verifyPayment($providerReference);
        return $result['status'];
    }

    public function refundPayment(Transaction $transaction, float $amount): array
    {
        $response = $this->client()->post("{$this->baseUrl}/refund", [
            'transaction' => $transaction->provider_reference,
            'amount' => (int) ($amount * 100),
            'currency' => $transaction->currency,
            'customer_note' => "Refund for transaction {$transaction->reference}",
        ]);

        if (!$response->successful() || !$response->json('status')) {
            Log::error('Paystack Refund Failed', ['response' => $response->body()]);
            throw new \Exception('Failed to process refund with Paystack: ' . $response->json('message'));
        }

        return [
            'success' => true,
            'refund_reference' => $response->json('data.reference') ?? 'ref_' . uniqid(),
        ];
    }

    public function initializePayout(Withdrawal $withdrawal): array
    {
        $bankAccount = $withdrawal->bank_account;

        $payload = [
            'type' => 'nuban',
            'name' => (string) ($bankAccount['account_name'] ?? 'Test Merchant'),
            'account_number' => (string) ($bankAccount['account_number'] ?? ''),
            'bank_code' => (string) ($bankAccount['bank_code'] ?? ''),
            'currency' => (string) ($withdrawal->currency ?? 'NGN'),
        ];

        Log::info('Paystack Payout Recipient Payload:', $payload);

        $recipientResponse = $this->client()->post("{$this->baseUrl}/transferrecipient", $payload);

        if (!$recipientResponse->successful() || !$recipientResponse->json('status')) {
            Log::error('Paystack Recipient Creation Failed', ['response' => $recipientResponse->body()]);
            throw new \Exception('Failed to create bank transfer recipient: ' . $recipientResponse->json('message'));
        }

        $recipientCode = $recipientResponse->json('data.recipient_code');

        $transferResponse = $this->client()->post("{$this->baseUrl}/transfer", [
            'source' => 'balance',
            'amount' => (int) ($withdrawal->amount * 100),
            'recipient' => (string) $recipientCode,
            'reference' => (string) $withdrawal->reference,
            'reason' => "Merchant Payout {$withdrawal->reference}",
        ]);

        if (!$transferResponse->successful() || !$transferResponse->json('status')) {
            Log::error('Paystack Transfer Initiation Failed', ['response' => $transferResponse->body()]);
            throw new \Exception('Payout provider rejected transfer: ' . $transferResponse->json('message'));
        }

        $transferData = $transferResponse->json('data');

        return [
            'success' => true,
            'provider_reference' => $transferData['transfer_code'] ?? $transferData['reference'],
            'status' => 'PROCESSING',
            'fee' => 0.00,
        ];
    }

    public function verifyPayout(string $providerReference): array
    {
        $response = $this->client()->get("{$this->baseUrl}/transfer/{$providerReference}");

        if (!$response->successful() || !$response->json('status')) {
            throw new \Exception('Failed to verify payout with Paystack.');
        }

        $data = $response->json('data');
        $paystackStatus = $data['status'] ?? 'pending';

        return [
            'success' => $paystackStatus === 'success',
            'status' => strtoupper($paystackStatus),
            'reason' => $data['failures'] ?? $data['gateway_response'] ?? null,
        ];
    }

    public function healthCheck(): bool
    {
        try {
            return $this->client()->get("{$this->baseUrl}/bank")->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function createMerchantSubaccount(Merchant $merchant): string
    {
        $response = $this->client()->post("{$this->baseUrl}/subaccount", [
            'business_name' => $merchant->business_name,
            'settlement_bank' => $merchant->bank_code ?? '058',
            'account_number' => $merchant->account_number ?? '0000000000',
            'percentage_charge' => 1.5,
        ]);

        if (!$response->successful() || !$response->json('status')) {
            Log::error('Paystack Subaccount Creation Failed', ['response' => $response->body()]);
            throw new \Exception('Failed to create provider subaccount: ' . $response->json('message'));
        }

        return $response->json('data.subaccount_code');
    }
}
