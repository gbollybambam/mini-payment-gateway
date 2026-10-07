<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Merchant;
use App\Models\WebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DispatchMerchantWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [30, 120, 300, 3600]; // PRD: Exponential backoff

    public function __construct(
        public Merchant $merchant,
        public string $event,
        public array $payload
    ) {}

    public function handle(): void
    {
        $settings = $this->merchant->settings;

        // Abort if no webhook URL is configured
        if (!$settings || empty($settings->webhook_url)) {
            return;
        }

        $url = $settings->webhook_url;
        $secret = $settings->webhook_secret;

        $fullPayload = [
            'event' => $this->event,
            'data' => $this->payload,
        ];

        $payloadJson = json_encode($fullPayload);
        $signature = hash_hmac('sha256', $payloadJson, $secret);

        try {
            // Fire the actual POST request to webhook.site or the merchant's server
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'x-gateway-signature' => $signature,
                ])
                ->post($url, $fullPayload);

            $isSuccessful = $response->successful();

            // Log the exact result to the database for the frontend to display
            WebhookDelivery::create([
                'merchant_id' => $this->merchant->id,
                'event' => $this->event,
                'target_url' => $url,
                'payload' => $fullPayload,
                'response_status' => $response->status(),
                'response_body' => substr($response->body(), 0, 1000), // Limit size to prevent DB bloat
                'is_successful' => $isSuccessful,
            ]);

            // If the merchant server threw a 500 error, throw exception to trigger a retry
            if (!$isSuccessful && $response->serverError()) {
                throw new \Exception("Webhook target responded with {$response->status()}");
            }
        } catch (\Exception $e) {
            // Log connection failures (e.g., DNS error, timeout)
            WebhookDelivery::create([
                'merchant_id' => $this->merchant->id,
                'event' => $this->event,
                'target_url' => $url,
                'payload' => $fullPayload,
                'response_status' => null,
                'response_body' => substr($e->getMessage(), 0, 1000),
                'is_successful' => false,
            ]);

            Log::error('Webhook Delivery Failed', [
                'merchant_id' => $this->merchant->id,
                'url' => $url,
                'error' => $e->getMessage()
            ]);

            throw $e; // Trigger job retry
        }
    }
}
