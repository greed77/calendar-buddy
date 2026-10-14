<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['poll_slot_id', 'choice'])]
class Vote extends Model
{
    public const YES = 'yes';
    public const MAYBE = 'maybe';
    public const NO = 'no';

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(PollSlot::class, 'poll_slot_id');
    }
}
