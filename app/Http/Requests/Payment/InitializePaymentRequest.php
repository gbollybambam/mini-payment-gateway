<?php

namespace App\Http\Requests\Payment;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class InitializePaymentRequest extends FormRequest
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
        return [
            'amount' => ['required', 'numeric', 'min:1', 'regex:/^\d{1,15}(?:\.\d{1,2})?$/'],
            'currency' => [
                'required',
                'string',
                'size:3',
                'uppercase',
                function (string $attribute, mixed $value, \Closure $fail) {
                    // Use $this->merchant->id if this is an API key protected route
                    $merchantId = $this->merchant ? $this->merchant->id : $this->user()->id;

                    $walletExists = \App\Models\Wallet::where('merchant_id', $merchantId)
                        ->where('currency', $value)
                        ->exists();

                    if (! $walletExists) {
                        $fail("Your account is not configured to accept {$value}.");
                    }
                },
            ],
            'customer_email' => ['required', 'email'],
            'reference' => ['required', 'string', 'max:100'],
            'callback_url' => ['nullable', 'url:http,https'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
