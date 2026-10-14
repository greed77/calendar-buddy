<?php

namespace App\Livewire;

use App\Exceptions\CalendarNotConnected;
use App\Mail\PollInvitation;
use App\Models\Poll;
use App\Models\Vote;
use App\Services\GoogleCalendar;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** The organizer's view of a poll: results, inviting people, and picking the final time. */
#[Layout('components.layouts.app')]
class ManagePoll extends Component
{
    private const MAX_INVITES_AT_ONCE = 50;

    #[Locked]
    public Poll $poll;

    /** Email addresses to invite, separated by commas, spaces or new lines. */
    public string $invitees = '';

    public ?string $notice = null;

    public ?string $problem = null;

    public function mount(Poll $poll): void
    {
        abort_unless($poll->user_id === auth()->id(), 404);

        $this->poll = $poll;
    }

    public function invite(): void
    {
        $this->reset('notice', 'problem');

        $emails = collect(preg_split('/[\s,;]+/', strtolower($this->invitees), -1, PREG_SPLIT_NO_EMPTY))->unique();

        if ($emails->isEmpty()) {
            $this->addError('invitees', 'Enter at least one email address.');

            return;
        }

        if ($emails->count() > self::MAX_INVITES_AT_ONCE) {
            $this->addError('invitees', 'You can invite up to '.self::MAX_INVITES_AT_ONCE.' people at a time.');

            return;
        }

        $invalid = $emails->reject(fn ($email) => Validator::make(['e' => $email], ['e' => 'email:rfc'])->passes());

        if ($invalid->isNotEmpty()) {
            $this->addError('invitees', 'These don\'t look like email addresses: '.$invalid->implode(', '));

            return;
        }

        $existing = $this->poll->participants()->whereNotNull('email')->pluck('email')->map(fn ($e) => strtolower($e));
        $new = $emails->diff($existing);

        foreach ($new as $email) {
            $participant = $this->poll->participants()->create([
                'email' => $email,
                'invited_at' => now(),
            ]);

            Mail::to($email)->queue(new PollInvitation($participant));
        }

        $skipped = $emails->count() - $new->count();
        $this->notice = 'Invited '.$new->count().' '.Str::plural('person', $new->count()).'.'
            .($skipped ? " {$skipped} already on the poll." : '');
        $this->invitees = '';
    }

    public function finalize(int $slotId): void
    {
        $this->reset('notice', 'problem');

        $slot = $this->poll->slots()->findOrFail($slotId);

        // Guard against double-clicks creating two calendar events.
        $lock = Cache::lock("poll:{$this->poll->id}:finalize", 30);

        if (! $lock->get()) {
            return;
        }

        try {
            $this->poll->refresh();

            if (! $this->poll->isOpen()) {
                $this->problem = 'This poll has already been finalized.';

                return;
            }

            $organizer = $this->poll->user;
            $attendees = $this->poll->participants()
                ->whereNotNull('email')
                ->pluck('email')
                ->reject(fn ($email) => strcasecmp($email, $organizer->email) === 0)
                ->unique()
                ->values()
                ->all();

            try {
                $eventId = app(GoogleCalendar::class)->createEvent($organizer, $this->poll, $slot, $attendees);
            } catch (CalendarNotConnected) {
                $this->problem = 'calendar_not_connected';

                return;
            } catch (RequestException $e) {
                Log::error('Creating Google Calendar event failed', ['poll' => $this->poll->id, 'error' => $e->getMessage()]);
                $this->problem = 'Google Calendar didn\'t accept the event. Nothing was sent. Please try again.';

                return;
            }

            $this->poll->forceFill([
                'status' => Poll::STATUS_FINALIZED,
                'final_slot_id' => $slot->id,
                'google_event_id' => $eventId,
            ])->save();

            $this->notice = 'Scheduled. The event is on your Google Calendar and invitations went to '
                .count($attendees).' '.Str::plural('person', count($attendees)).'.';
        } finally {
            $lock->release();
        }
    }

    public function delete()
    {
        $this->poll->delete();

        return redirect()->route('dashboard')->with('status', 'Poll deleted.');
    }

    public function render()
    {
        $this->poll->load(['slots', 'finalSlot', 'participants' => fn ($q) => $q->with('votes')->orderByRaw('responded_at is null')->orderBy('responded_at')]);

        $votes = $this->poll->participants->mapWithKeys(fn ($p) => [$p->id => $p->votes->pluck('choice', 'poll_slot_id')]);

        // Rank slots by yes votes, then "if need be" votes.
        $scores = $this->poll->slots->mapWithKeys(fn ($slot) => [$slot->id => [
            'yes' => $votes->filter(fn ($v) => ($v[$slot->id] ?? null) === Vote::YES)->count(),
            'maybe' => $votes->filter(fn ($v) => ($v[$slot->id] ?? null) === Vote::MAYBE)->count(),
        ]]);
        $best = $scores->max(fn ($s) => $s['yes'] * 1000 + $s['maybe']);

        return view('livewire.manage-poll', [
            'timezone' => auth()->user()->timezone,
            'votes' => $votes,
            'scores' => $scores,
            'bestSlotIds' => $best > 0 ? $scores->filter(fn ($s) => $s['yes'] * 1000 + $s['maybe'] === $best)->keys()->all() : [],
            'attendeeCount' => $this->poll->participants->whereNotNull('email')
                ->reject(fn ($p) => strcasecmp($p->email, auth()->user()->email) === 0)->count(),
        ])->title($this->poll->title);
    }
}
