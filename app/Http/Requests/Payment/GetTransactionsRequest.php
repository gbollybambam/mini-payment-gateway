<?php

namespace App\Http\Requests\Payment;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class GetTransactionsRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date', 'before_or_equal:today'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', 'string'],
            'amount' => ['nullable', 'numeric'],
            'currency' => ['nullable', 'string', 'size:3'],
            'provider' => ['nullable', 'string'],
            'reference' => ['nullable', 'string'],
            'customer_email' => ['nullable', 'email'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
