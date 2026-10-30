<?php

namespace App\Http\Requests\Withdrawal;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class InitializeB2BWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:100', 'decimal:0,2'],
            'currency' => ['required', 'string', 'size:3', 'uppercase'],
            'reference' => ['required', 'string', 'max:100', 'unique:withdrawals,reference'],

            // STRICT ENFORCEMENT FOR B2B: Verification token is REQUIRED
            'verification_token' => ['required', 'string'],

            'metadata' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'verification_token.required' => 'B2B payouts require a valid verification_token from /v1/withdrawals/resolve-account.',
        ];
    }
}
