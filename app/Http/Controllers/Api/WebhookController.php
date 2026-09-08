<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\PaymentProviders\Contracts\PaymentProviderInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Psy\Util\Json;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends Controller
{
    public function __construct(
        private PaymentProviderInterface $paymentProvider
    ) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        if (strtolower($provider) !== 'cray') {
            return response()->json(['status' => 'error', 'message' => 'Unsupported provider.'], Response::HTTP_BAD_REQUEST);
        }

        $payload = $request->all();

        $signature = $request->header('x-cray-signature', '');

        try {
            $verifiedData = $this->paymentProvider->processWebhook($payload, $signature);
        } catch (\Exception $e) {
            Log::warning("Invalid webhook signature from provider: {$provider}", ['error' => $e->getMessage()]);
            return response()->json(['status' => 'error', 'message' => 'Invalid signature.'], Response::HTTP_UNAUTHORIZED);
        }

        if (! $verifiedData['is_valid']) {
            return response()->json(['status' => 'error', 'message' => 'Webhook validation failed.'], Response::HTTP_UNAUTHORIZED);
        }

        $transaction = Transaction::where('provider_reference', $verifiedData['provider_reference'])->first();

        if (! $transaction) {
            Log::info("Webhook received for unknown transaction.", ['reference' => $verifiedData['provider_reference']]);
            return response()->json(['status' => 'success'], Response::HTTP_OK);
        }

        if (in_array($transaction->status, [PaymentStatus::SUCCESS, PaymentStatus::FAILED])) {
            return response()->json(['status' => 'success', 'message' => 'Transaction already processed.'], Response::HTTP_OK);
        }

        if ($verifiedData['event'] === 'payment.success') {
            $transaction->update(['status' => PaymentStatus::SUCCESS]);

            \App\Jobs\DispatchMerchantWebhook::dispatch(
                $transaction->merchant,
                'payment.success',
                $transaction->toArray()
            );

        } elseif ($verifiedData['event'] === 'payment.failed') {
            $transaction->update(['status' => PaymentStatus::FAILED]);

            \App\Jobs\DispatchMerchantWebhook::dispatch(
                $transaction->merchant,
                'payment.failed',
                $transaction->toArray()
            );
        }

        return response()->json(['status' => 'success'], Response::HTTP_OK);
    }
}
