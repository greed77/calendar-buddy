<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['user_id', 'name', 'email', 'invited_at', 'responded_at'])]
class Participant extends Model
{
    protected static function booted(): void
    {
        static::creating(function (Participant $participant) {
            $participant->token ??= Str::random(48);
        });
    }

    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class);
    }

    /** Link that opens the poll as this participant, so they can change their answers. */
    public function personalUrl(): string
    {
        return route('polls.vote', ['poll' => $this->poll, 'token' => $this->token]);
    }
}
