<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'payable_type',
        'payable_id',
        'transaction_id',
        'reference',
        'type',
        'method',
        'amount',
        'currency',
        'status',
        'metadata',
        'paid_at',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'metadata' => 'array',
        'paid_at' => 'datetime',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payable()
    {
        return $this->morphTo();
    }

    // Scopes
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByMethod($query, string $method)
    {
        return $query->where('method', $method);
    }

    // Helpers
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function markAsCompleted(): self
    {
        $this->status = 'completed';
        $this->paid_at = now();
        $this->save();
        
        return $this;
    }

    public function markAsFailed(string $reason = null): self
    {
        $this->status = 'failed';
        if ($reason) {
            $this->notes = $reason;
        }
        $this->save();
        
        return $this;
    }

    public function markAsRefunded(): self
    {
        $this->status = 'refunded';
        $this->save();
        
        return $this;
    }

    public static function generateReference(): string
    {
        return 'PAY-' . strtoupper(uniqid() . mt_rand(1000, 9999));
    }
}
