<?php

namespace App\Services\PaymentProviders\Contracts;

use App\Models\Transaction;

interface PaymentProviderInterface
{
    /**
     * Initialize a payment with the provider and return the checkout URL and provider reference.
     */
    public function initializePayment(Transaction $transaction): array;

    /**
     * Independently verify the payment status directly with the provider.
     */
    public function verifyPayment(string $providerReference): array;

    /**
     * Process and validate an incoming webhook payload from the provider.
     */
    public function processWebhook(array $payload, string $signature): array;

    /**
     * Retrieve the current normalized status of a payment.
     */
    public function getPaymentStatus(string $providerReference): string;

    /**
     * Initiate a refund
     */
    public function refundPayment(Transaction $transaction, float $amount): array;

    /**
     * Create a payout/withdrawal to a bank account.
     */
    public function createPayout(array $bankDetails, float $amount, string $currency): array;
}