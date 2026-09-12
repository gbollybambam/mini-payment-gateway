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
     * Initialize a payout/withdrawal to a merchant's bank account.
     */
    public function initializePayout(\App\Models\Withdrawal $withdrawal): array;

    /**
     * Verify the status of a payout.
     */
    public function verifyPayout(string $providerReference): array;
}