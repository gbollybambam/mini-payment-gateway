<?php

namespace App\Http\Requests\Auth;

use App\Rules\ValidTurnstileToken;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rule;

class RegisterMerchantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Handled by API middleware routing
    }

    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email:rfc,dns',
                'max:255',
                // FIX: Only throw "Email Taken" if the account is actually verified.
                Rule::unique('merchants', 'email')->whereNotNull('email_verified_at'),
            ],
            'cf-turnstile-response' => ['required', 'string', new ValidTurnstileToken()],

            // Trade-off: Regex enforces E.164 strictness but might reject edge-case valid numbers. 
            // Benefit: Normalizes all contact data for webhook delivery logs and support.
            'phone' => ['nullable', 'string', 'regex:/^\+[1-9]\d{1,14}$/', 'max:20'],

            // Lock down supported operational limits based on the Next.js form mappings
            'country' => ['required', 'string', 'size:2', Rule::in(['NG', 'US', 'GB'])],
            'currency' => ['required', 'string', 'size:3', 'uppercase', Rule::in(['NGN', 'USD', 'GBP'])],

            'business_type' => ['required', 'string', 'max:100', Rule::in(['Individual', 'LLC', 'Non-Profit'])],

            'password' => [
                'required',
                'string',
                Password::min(12) // Bumped from 8 to 12 for financial gateway standards
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols()
                    ->uncompromised(), // Note: Calls HaveIBeenPwned API. Adds ~200ms latency but massively boosts security.
            ],

            // Ensure confirmation is explicitly validated
            'password_confirmation' => ['required', 'same:password'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'The phone number must be a valid E.164 format (e.g., +2348012345678).',
            'currency.in' => 'The selected currency is not currently supported by the gateway.',
        ];
    }
}
