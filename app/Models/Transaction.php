<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'merchant_id',
        'provider',
        'customer_email',
        'reference',
        'provider_reference',
        'amount',
        'currency',
        'fee',
        'net_amount',
        'status',
        'payment_method',
        'metadata',
        'checkout_url',
        'idempotency_key',
        'idempotency_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'fee' => 'decimal:4',
            'net_amount' => 'decimal:4',
            'metadata' => 'array',
            'status' => PaymentStatus::class,
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
