<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Withdrawal extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'payment_method_id',
        'reference',
        'amount',
        'fee',
        'status',
        'notes',
        'processed_at',
        'processed_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'fee' => 'decimal:2',
        'processed_at' => 'datetime',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function processor()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    // Helpers
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function getTotalAttribute(): float
    {
        return $this->amount + $this->fee;
    }

    public function getNetAmountAttribute(): float
    {
        return $this->amount - $this->fee;
    }

    public function process(int $processorId): self
    {
        $this->status = 'processing';
        $this->processed_by = $processorId;
        $this->save();
        
        return $this;
    }

    public function complete(): self
    {
        $this->status = 'completed';
        $this->processed_at = now();
        $this->save();
        
        return $this;
    }

    public function reject(string $reason): self
    {
        $this->status = 'rejected';
        $this->notes = $reason;
        $this->processed_at = now();
        $this->save();
        
        return $this;
    }

    public static function generateReference(): string
    {
        return 'WDR-' . strtoupper(uniqid() . mt_rand(1000, 9999));
    }
}
