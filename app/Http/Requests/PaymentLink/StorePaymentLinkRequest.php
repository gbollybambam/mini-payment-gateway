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
        return [
            'amount' => ['required', 'numeric', 'min:1'],
            'currency' => [
                'required',
                'string',
                'size:3',
                'uppercase',
                // Custom rule to ensure the wallet exists
                function (string $attribute, mixed $value, \Closure $fail) {
                    $walletExists = \App\Models\Wallet::where('merchant_id', $this->user()->id)
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
                Rule::unique('payment_links')->where(fn ($query) => $query->where('merchant_id', $this->user()->id)),
            ],
            'customer_information' => ['nullable', 'array'],
            'customer_information.email' => ['nullable', 'email'],
            'customer_information.name' => ['nullable', 'string', 'max:255'],
            'expiry' => ['nullable', 'date', 'after:now'],
            'redirect_url' => ['nullable', 'url', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
