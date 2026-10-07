<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiKey extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'environment',
        'key_prefix',
        'secret_key',
        'scopes',       // <-- ADDED
        'expires_at',   // <-- ADDED
        'revoked_at',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    // Helper to check if the key is still valid
    public function isValid(): bool
    {
        if ($this->revoked_at !== null) return false;
        if ($this->expires_at !== null && $this->expires_at->isPast()) return false;

        return true;
    }
}
