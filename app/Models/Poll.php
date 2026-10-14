<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title', 'description', 'location', 'duration_minutes', 'timezone', 'status'])]
class Poll extends Model
{
    use HasUlids;

    public const STATUS_OPEN = 'open';
    public const STATUS_FINALIZED = 'finalized';
    public const STATUS_CLOSED = 'closed';

    /** Only public_id is a ULID; the primary key stays an auto-increment integer. */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function slots(): HasMany
    {
        return $this->hasMany(PollSlot::class)->orderBy('starts_at');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class);
    }

    public function finalSlot(): BelongsTo
    {
        return $this->belongsTo(PollSlot::class, 'final_slot_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /** The public link anyone can use to answer the poll. */
    public function shareUrl(): string
    {
        return route('polls.vote', $this);
    }
}
