<?php

namespace App\Livewire;

use App\Models\Participant;
use App\Models\Poll;
use App\Models\Vote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The public page where anyone with the link answers a poll. People are
 * recognised by their personal token (from an invitation email, or saved
 * in the session after answering), never by a login.
 */
#[Layout('components.layouts.app')]
class VotePoll extends Component
{
    #[Locked]
    public Poll $poll;

    #[Locked]
    public ?int $participantId = null;

    /** Invited people can't change the address their invitation went to. */
    #[Locked]
    public bool $emailLocked = false;

    public string $name = '';

    public string $email = '';

    /** slot id => yes|maybe|no */
    public array $choices = [];

    /** Viewer's timezone, detected from the browser. */
    public string $timezone = 'UTC';

    public bool $saved = false;

    public function mount(Poll $poll, ?string $token = null): void
    {
        $this->poll = $poll;
        $this->timezone = auth()->user()?->timezone ?? $poll->timezone;

        $participant = $this->findParticipant($token);

        if ($participant) {
            $this->participantId = $participant->id;
            $this->emailLocked = $participant->invited_at !== null && filled($participant->email);
            $this->name = (string) $participant->name;
            $this->email = (string) $participant->email;
            $this->choices = $participant->votes->pluck('choice', 'poll_slot_id')->all();
        } elseif ($user = auth()->user()) {
            $this->name = $user->name;
            $this->email = $user->email;
        }
    }

    public function setTimezone(string $timezone): void
    {
        if (in_array($timezone, timezone_identifiers_list(), true)) {
            $this->timezone = $timezone;
        }
    }

    public function submit(): void
    {
        if (! $this->poll->fresh()->isOpen()) {
            $this->addError('poll', 'This poll is no longer taking answers.');

            return;
        }

        // The page is public, so keep anyone from flooding a poll with fake answers.
        if (RateLimiter::tooManyAttempts($limiterKey = 'vote:'.request()->ip(), 20)) {
            $this->addError('poll', 'Too many submissions. Please wait a minute and try again.');

            return;
        }
        RateLimiter::hit($limiterKey);

        $slotIds = $this->poll->slots()->pluck('id');

        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'choices' => ['array'],
            'choices.*' => [Rule::in([Vote::YES, Vote::MAYBE, Vote::NO])],
        ]);

        $participant = DB::transaction(function () use ($slotIds) {
            $participant = $this->participantId
                ? Participant::where('poll_id', $this->poll->id)->findOrFail($this->participantId)
                : $this->poll->participants()->make(['user_id' => auth()->id()]);

            $participant->name = $this->name;
            if (! $this->emailLocked) {
                $participant->email = $this->email ? strtolower($this->email) : null;
            }
            $participant->responded_at = now();
            $participant->save();

            // Anything left unanswered counts as "no".
            foreach ($slotIds as $slotId) {
                $participant->votes()->updateOrCreate(
                    ['poll_slot_id' => $slotId],
                    ['choice' => $this->choices[$slotId] ?? Vote::NO],
                );
            }

            return $participant;
        });

        $this->participantId = $participant->id;
        session()->put($this->sessionKey(), $participant->token);
        $this->saved = true;
    }

    public function render()
    {
        $this->poll->load(['user', 'finalSlot', 'slots' => fn ($q) => $q->withCount([
            'votes as yes_count' => fn ($v) => $v->where('choice', Vote::YES),
            'votes as maybe_count' => fn ($v) => $v->where('choice', Vote::MAYBE),
        ])]);

        return view('livewire.vote-poll', [
            'days' => $this->poll->slots->groupBy(fn ($slot) => $slot->starts_at->setTimezone($this->timezone)->toDateString()),
            'personalUrl' => $this->participantId ? Participant::find($this->participantId)?->personalUrl() : null,
        ])->title($this->poll->title);
    }

    private function findParticipant(?string $token): ?Participant
    {
        $participants = $this->poll->participants()->with('votes');

        if ($token) {
            $participant = $participants->where('token', $token)->first();
            abort_unless($participant, 404);
            session()->put($this->sessionKey(), $token);

            return $participant;
        }

        if ($saved = session($this->sessionKey())) {
            if ($participant = (clone $participants)->where('token', $saved)->first()) {
                return $participant;
            }
        }

        return auth()->check() ? $participants->where('user_id', auth()->id())->first() : null;
    }

    private function sessionKey(): string
    {
        return "poll.{$this->poll->id}.participant";
    }
}
