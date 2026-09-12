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
            'amount' => ['required', 'numeric', 'min:100'], // Minimum 100 limit
            'currency' => ['required', 'string', 'size:3', 'uppercase'],
            'reference' => ['required', 'string', 'max:100', 'unique:withdrawals,reference'],

            // Bank details flattened for the API request
            'bank_code' => ['required', 'string'],
            'account_number' => ['required', 'string', 'max:20'],
            'account_name' => ['required', 'string', 'max:255'],

            'metadata' => ['nullable', 'array'],
        ];
    }
}
