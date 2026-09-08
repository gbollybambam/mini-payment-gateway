<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebhookDelivery extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'merchant_id',
        'event',
        'target_url',
        'payload',
        'response_status',
        'response_body',
        'is_successful',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'is_successful' => 'boolean',
        ];
    }
}
