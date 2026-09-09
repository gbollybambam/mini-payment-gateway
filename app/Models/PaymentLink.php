<?php

namespace App\Models;

use App\Enums\PaymentLinkStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentLink extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'merchant_id',
        'reference',
        'checkout_token',
        'amount',
        'currency',
        'description',
        'customer_information',
        'status',
        'expiry',
        'redirect_url',
        'payment_id',
        'metadata',
    ];

    protected function cast(): array
    {
        return [
            'amount' => 'decimal:4',
            'customer_information' => 'array',
            'metadata' => 'array',
            'expiry' => 'datetime',
            'status' => PaymentLinkStatus::class,
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'payment_id');
    }
}
