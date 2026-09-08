<?php

namespace App\Jobs;

use App\Models\Merchant;
use App\Models\WebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class DispatchMerchantWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;

    public function backoff(): array
    {
        return [10, 30, 120, 300, 900];
    }

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Merchant $merchant,
        public string $event,
        public array $data
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $settings = $this->merchant->settings;

        if (! $settings || ! $settings->webhook_url || ! $settings->webhook_secret) {
            return;
        }

        $payload = [
            'event' => $this->event,
            'data' => $this->data,
            'timestamp' => now()->toIso8601String(),
        ];

        $jsonPayload = json_encode($payload);
        $signature = hash_hmac('sha256', $jsonPayload, $settings->webhook_secret);

        try {
            $response = Http::timeout(5)
                ->withHeaders([
                    'x-gateway-signature' => $signature,
                    'Content-Type' => 'application/json',
                ])
                ->post($settings->webhook_url, $payload);
            
            $isSuccessful = $response->successful();

            WebhookDelivery::create([
                'merchant_id' => $this->merchant->id,
                'event' => $this->event,
                'target_url' => $settings->webhook_url,
                'payload' => $payload,
                'response_status' => $response->status(),
                'response_body' => substr($response->body(), 0, 1000), 
                'is_successful' => $isSuccessful,
            ]);

            if (! $isSuccessful) {
                throw new \Exception("Webhook failed with status: {$response->status()}");
            }

        } catch (\Exception $e) {
            if (!isset($isSuccessful)) {
                WebhookDelivery::create([
                    'merchant_id' => $this->merchant->id,
                    'event' => $this->event,
                    'target_url' => $settings->webhook_url,
                    'payload' => $payload,
                    'response_body' => $e->getMessage(),
                    'is_successful' => false,
                ]);
            }

            throw $e;
        }
    }
}
