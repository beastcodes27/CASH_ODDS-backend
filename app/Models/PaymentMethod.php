<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'provider',
        'account_number',
        'account_name',
        'last_four',
        'is_default',
        'metadata',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'metadata' => 'array',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function withdrawals()
    {
        return $this->hasMany(Withdrawal::class);
    }

    // Scopes
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    // Helpers
    public function setAsDefault(): self
    {
        // Remove default from other methods
        $this->user->paymentMethods()
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);
        
        $this->is_default = true;
        $this->save();
        
        return $this;
    }

    public function maskAccountNumber(): string
    {
        if (!$this->account_number) {
            return '';
        }
        
        $length = strlen($this->account_number);
        if ($length <= 4) {
            return $this->account_number;
        }
        
        return str_repeat('*', $length - 4) . substr($this->account_number, -4);
    }
}
