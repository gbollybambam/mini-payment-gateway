<?php

namespace App\Http\Requests\Withdrawal;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class InitializeWithdrawalRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'min:100', 'decimal:0,2'],
            'currency' => ['required', 'string', 'size:3', 'uppercase'],
            'reference' => ['required', 'string', 'max:100', 'unique:withdrawals,reference'],

            // Verification Token for Untamperable B2B Payouts
            'verification_token' => ['nullable', 'string'],

            // Bank details required IF verification_token is not provided (UI Dashboard)
            'bank_code' => ['required_without:verification_token', 'nullable', 'string', 'max:20'],
            'account_number' => ['required_without:verification_token', 'nullable', 'string', 'max:20', 'regex:/^[0-9]+$/'],
            'account_name' => ['required_without:verification_token', 'nullable', 'string', 'max:255'],

            'metadata' => ['nullable', 'array'],
        ];
    }
}
