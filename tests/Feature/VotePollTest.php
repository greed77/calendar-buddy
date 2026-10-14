<?php

namespace Tests\Feature;

use App\Livewire\VotePoll;
use App\Models\Participant;
use App\Models\Poll;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VotePollTest extends TestCase
{
    use RefreshDatabase;

    private Poll $poll;

    protected function setUp(): void
    {
        parent::setUp();

        $this->poll = User::factory()->create()->polls()->create([
            'title' => 'Kickoff', 'duration_minutes' => 60, 'timezone' => 'America/Chicago',
        ]);
        $this->poll->slots()->createMany([
            ['starts_at' => CarbonImmutable::parse('2026-10-20 15:00'), 'ends_at' => CarbonImmutable::parse('2026-10-20 16:00')],
            ['starts_at' => CarbonImmutable::parse('2026-10-21 15:00'), 'ends_at' => CarbonImmutable::parse('2026-10-21 16:00')],
        ]);
    }

    public function test_guest_can_open_the_share_link(): void
    {
        $this->get(route('polls.vote', $this->poll))
            ->assertOk()
            ->assertSee('Kickoff')
            ->assertSee('Save answers');
    }

    public function test_guest_answers_and_unanswered_slots_count_as_no(): void
    {
        [$first, $second] = $this->poll->slots;

        Livewire::test(VotePoll::class, ['poll' => $this->poll])
            ->set('name', 'Jordan Client')
            ->set('email', 'Jordan@Client.com')
            ->set("choices.{$first->id}", 'yes')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('saved', true);

        $participant = Participant::sole();
        $this->assertSame('jordan@client.com', $participant->email);
        $this->assertNotNull($participant->responded_at);
        $this->assertSame(
            [$first->id => 'yes', $second->id => 'no'],
            $participant->votes->pluck('choice', 'poll_slot_id')->all(),
        );
    }

    public function test_personal_link_loads_and_updates_the_same_participant(): void
    {
        $participant = $this->poll->participants()->create(['email' => 'sam@client.com', 'invited_at' => now()]);
        $slot = $this->poll->slots->first();

        Livewire::test(VotePoll::class, ['poll' => $this->poll, 'token' => $participant->token])
            ->assertSet('emailLocked', true)
            ->set('name', 'Sam')
            ->set('email', 'attacker@evil.com') // locked for invitees; must be ignored
            ->set("choices.{$slot->id}", 'maybe')
            ->call('submit')
            ->assertHasNoErrors();

        $participant->refresh();
        $this->assertSame(1, Participant::count());
        $this->assertSame('Sam', $participant->name);
        $this->assertSame('sam@client.com', $participant->email);
        $this->assertSame('maybe', $participant->votes()->where('poll_slot_id', $slot->id)->value('choice'));
    }

    public function test_wrong_token_is_not_found(): void
    {
        $this->get(route('polls.vote', ['poll' => $this->poll, 'token' => 'nope']))->assertNotFound();
    }

    public function test_cannot_answer_a_finalized_poll(): void
    {
        $this->poll->update(['status' => Poll::STATUS_FINALIZED]);

        Livewire::test(VotePoll::class, ['poll' => $this->poll])
            ->set('name', 'Late')
            ->call('submit')
            ->assertHasErrors('poll');

        $this->assertSame(0, Participant::count());
    }

    public function test_participant_id_cannot_be_tampered_with(): void
    {
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);

        Livewire::test(VotePoll::class, ['poll' => $this->poll])->set('participantId', 1);
    }
}
