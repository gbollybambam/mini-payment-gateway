<?php

namespace App\Models;

use App\Enums\WithdrawalStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Withdrawal extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'merchant_id',
        'provider',
        'reference',
        'provider_reference',
        'amount',
        'fee',
        'currency',
        'status',
        'bank_account',
        'metadata',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'fee' => 'decimal:4',
            'bank_account' => 'array',
            'metadata' => 'array',
            'status' => WithdrawalStatus::class,
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
