<?php

namespace App\Http\Requests\Checkout;

use Illuminate\Foundation\Http\FormRequest;

class ProcessCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_email' => ['required', 'email', 'max:255'],
            'customer_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
