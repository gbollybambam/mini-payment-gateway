<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Merchant extends Authenticable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    /**
     * The attributes that are mass assignable.
     * 
     * @var array<int, string>
     */
    protected $fillable = [
        'business_name',
        'email',
        'phone',
        'country',
        'currency',
        'business_type',
        'password',
        'email_verified_at',
        'status',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization
     * 
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast
     * 
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function settings(): HasOne
    {
        return $this->hasOne(MerchantSetting::class);
    }

    public function paymentLinks(): HasMany
    {
        return $this->hasMany(PaymentLink::class);
    }

    public function wallets(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Wallet::class);
    }

    public function transactions()
    {
        return $this->hasMany(\App\Models\Transaction::class);
    }

    public function withdrawals()
    {
        return $this->hasMany(\App\Models\Withdrawal::class);
    }

    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /**
     * Send the password reset notification asynchronously pointing to the Next.js SPA.
     */
    public function sendPasswordResetNotification($token)
    {
        dispatch(function () use ($token) {
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            $url = $frontendUrl . '/reset-password?token=' . $token . '&email=' . urlencode($this->email);

            \Illuminate\Auth\Notifications\ResetPassword::createUrlUsing(function () use ($url) {
                return $url;
            });

            $this->notify(new \Illuminate\Auth\Notifications\ResetPassword($token));
        });
    }
}
