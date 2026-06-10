<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tip extends Model
{
    use HasFactory;

    protected $fillable = [
        'tipster_id',
        'sport_id',
        'league_id',
        'home_team',
        'away_team',
        'match_date',
        'prediction',
        'odds_type',
        'odds',
        'confidence',
        'analysis',
        'status',
        'home_score',
        'away_score',
        'is_premium',
        'is_featured',
    ];

    protected $casts = [
        'match_date' => 'datetime',
        'odds' => 'decimal:2',
        'is_premium' => 'boolean',
        'is_featured' => 'boolean',
        'home_score' => 'integer',
        'away_score' => 'integer',
    ];

    // Relationships
    public function tipster()
    {
        return $this->belongsTo(User::class, 'tipster_id');
    }

    public function sport()
    {
        return $this->belongsTo(Sport::class);
    }

    public function league()
    {
        return $this->belongsTo(League::class);
    }

    // Scopes
    public function scopePublished($query)
    {
        return $query->where('match_date', '>', now());
    }

    public function scopePast($query)
    {
        return $query->where('match_date', '<=', now());
    }

    public function scopePremium($query)
    {
        return $query->where('is_premium', true);
    }

    public function scopeFree($query)
    {
        return $query->where('is_premium', false);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeBySport($query, int $sportId)
    {
        return $query->where('sport_id', $sportId);
    }

    public function scopeByLeague($query, int $leagueId)
    {
        return $query->where('league_id', $leagueId);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeWon($query)
    {
        return $query->where('status', 'won');
    }

    public function scopeLost($query)
    {
        return $query->where('status', 'lost');
    }

    public function scopeToday($query)
    {
        return $query->whereDate('match_date', today());
    }

    public function scopeUpcoming($query, int $days = 7)
    {
        return $query->whereBetween('match_date', [now(), now()->addDays($days)]);
    }

    // Helpers
    public function isWon(): ?bool
    {
        return $this->status === 'won' ? true : ($this->status === 'lost' ? false : null);
    }

    public function getMatchResultAttribute(): ?string
    {
        if ($this->home_score === null || $this->away_score === null) {
            return null;
        }
        return "{$this->home_score} - {$this->away_score}";
    }

    public function setResult(int $homeScore, int $awayScore): self
    {
        $this->home_score = $homeScore;
        $this->away_score = $awayScore;
        
        // Simple result determination - can be expanded based on prediction type
        $this->status = $this->determineResult();
        $this->save();
        
        return $this;
    }

    protected function determineResult(): string
    {
        // Basic logic - should be enhanced based on prediction type
        return 'void';
    }
}
