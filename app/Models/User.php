<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'avatar',
        'role',
        'status',
        'balance',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'balance' => 'decimal:2',
    ];

    // Relationships
    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription()
    {
        return $this->hasOne(Subscription::class)
            ->where('status', 'active')
            ->where('ends_at', '>', now())
            ->latest();
    }

    public function tips()
    {
        return $this->hasMany(Tip::class, 'tipster_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function paymentMethods()
    {
        return $this->hasMany(PaymentMethod::class);
    }

    public function withdrawals()
    {
        return $this->hasMany(Withdrawal::class);
    }

    // Scopes
    public function scopeTipsters($query)
    {
        return $query->where('role', 'tipster');
    }

    public function scopeSubscribers($query)
    {
        return $query->where('role', 'subscriber');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    // Helpers
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isTipster(): bool
    {
        return $this->role === 'tipster';
    }

    public function isSubscriber(): bool
    {
        return $this->role === 'subscriber';
    }

    public function hasActiveSubscription(): bool
    {
        return $this->subscriptions()
            ->where('status', 'active')
            ->where('ends_at', '>', now())
            ->exists();
    }

    public function canAccessPremiumTip(Tip $tip): bool
    {
        if (!$tip->is_premium) {
            return true;
        }
        return $this->isAdmin() || $this->hasActiveSubscription();
    }
}
