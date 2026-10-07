<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;

class ValidTurnstileToken implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (app()->environment('local', 'testing')) {
            return; // Skip CAPTCHA during local development and testing
        }

        $response = Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret' => env('TURNSTILE_SECRET_KEY'),
            'response' => $value,
        ]);

        if (! $response->json('success')) {
            $fail('The bot protection check failed. Please refresh and try again.');
        }
    }
}
