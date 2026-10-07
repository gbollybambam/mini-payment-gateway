<?php

namespace App\Http\Requests\PaymentLink;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentLinkRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // 1. THE FIX: Resolve the true Merchant context inside the Form Request
        $merchant = $this->user()->merchant ?? $this->user();

        return [
            'amount' => ['required', 'numeric', 'min:1'],
            'currency' => [
                'required',
                'string',
                'size:3',
                'uppercase',
                // 2. THE FIX: Use $merchant->id instead of $this->user()->id
                function (string $attribute, mixed $value, \Closure $fail) use ($merchant) {
                    $walletExists = \App\Models\Wallet::where('merchant_id', $merchant->id)
                        ->where('currency', $value)
                        ->exists();

                    if (! $walletExists) {
                        $fail("You do not have an active wallet setup for {$value}. Please create one first.");
                    }
                },
            ],
            'description' => ['required', 'string', 'max:255'],
            'reference' => [
                'required',
                'string',
                'max:100',
                // 3. THE FIX: Ensure uniqueness checks against the parent Merchant's links
                Rule::unique('payment_links')->where(fn($query) => $query->where('merchant_id', $merchant->id)),
            ],

            // 4. THE FIX: Validate 'customer_email' exactly as the React frontend sends it
            'customer_email' => ['nullable', 'email', 'max:255'],

            'expiry' => ['nullable', 'date', 'after:now'],
            'redirect_url' => ['nullable', 'url', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
