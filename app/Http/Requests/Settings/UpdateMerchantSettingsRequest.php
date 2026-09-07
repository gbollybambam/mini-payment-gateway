<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMerchantSettingsRequest extends FormRequest
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
            // Business settings
            'business_name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'logo_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'website_url' => ['sometimes', 'nullable', 'url', 'max:255'],

            // Checkout settings
            'brand_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'checkout_logo_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'success_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'cancel_url' => ['sometimes', 'nullable', 'url', 'max:255'],

            // Developer settings
            'webhook_url' => ['sometimes', 'nullable', 'url', 'max:255'],
        ];
    }
}
