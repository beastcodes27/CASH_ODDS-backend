<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'subscription_plan_id',
        'starts_at',
        'ends_at',
        'status',
        'payment_reference',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function plan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function payment()
    {
        return $this->morphOne(Payment::class, 'payable');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where('ends_at', '>', now());
    }

    public function scopeExpired($query)
    {
        return $query->where('ends_at', '<=', now());
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    // Helpers
    public function isActive(): bool
    {
        return $this->status === 'active' && $this->ends_at > now();
    }

    public function daysRemaining(): int
    {
        if (!$this->isActive()) {
            return 0;
        }
        return max(0, now()->diffInDays($this->ends_at, false));
    }

    public function renew(int $days = null): self
    {
        $days = $days ?? $this->plan->duration_days;
        
        if ($this->isActive()) {
            $this->ends_at = $this->ends_at->addDays($days);
        } else {
            $this->starts_at = now();
            $this->ends_at = now()->addDays($days);
        }
        
        $this->status = 'active';
        $this->save();
        
        return $this;
    }
}
