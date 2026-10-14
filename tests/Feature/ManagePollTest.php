<?php

namespace Tests\Feature;

use App\Livewire\ManagePoll;
use App\Mail\PollInvitation;
use App\Models\Poll;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class ManagePollTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Poll $poll;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create([
            'email' => 'pat@example.com',
            'google_access_token' => 'access',
            'google_refresh_token' => 'refresh',
            'google_token_expires_at' => now()->addHour(),
        ]);
        $this->poll = $this->owner->polls()->create([
            'title' => 'Kickoff', 'duration_minutes' => 60, 'timezone' => 'America/Chicago',
        ]);
        $this->poll->slots()->create([
            'starts_at' => CarbonImmutable::parse('2026-10-20 15:00'),
            'ends_at' => CarbonImmutable::parse('2026-10-20 16:00'),
        ]);
    }

    public function test_invites_new_addresses_and_skips_existing_ones(): void
    {
        Mail::fake();
        $this->poll->participants()->create(['email' => 'already@client.com', 'invited_at' => now()]);

        Livewire::actingAs($this->owner)->test(ManagePoll::class, ['poll' => $this->poll])
            ->set('invitees', "Alex@Client.com, sam@client.com\nalready@client.com alex@client.com")
            ->call('invite')
            ->assertHasNoErrors()
            ->assertSet('notice', 'Invited 2 people. 1 already on the poll.');

        $this->assertSame(3, $this->poll->participants()->count());
        Mail::assertQueued(PollInvitation::class, 2);
        Mail::assertQueued(PollInvitation::class, fn ($mail) => $mail->hasTo('alex@client.com')
            && $mail->hasReplyTo('pat@example.com'));
    }

    public function test_invalid_addresses_are_rejected(): void
    {
        Mail::fake();

        Livewire::actingAs($this->owner)->test(ManagePoll::class, ['poll' => $this->poll])
            ->set('invitees', 'good@client.com, not-an-email')
            ->call('invite')
            ->assertHasErrors('invitees');

        Mail::assertNothingQueued();
    }

    public function test_invitation_email_contains_personal_link(): void
    {
        $participant = $this->poll->participants()->create(['email' => 'alex@client.com', 'invited_at' => now()]);

        (new PollInvitation($participant))
            ->assertSeeInHtml($participant->personalUrl())
            ->assertSeeInHtml('Kickoff');
    }

    public function test_finalize_creates_calendar_event_inviting_everyone_with_an_email(): void
    {
        Http::fake(['www.googleapis.com/calendar/*' => Http::response(['id' => 'evt_123'])]);

        $this->poll->participants()->create(['email' => 'alex@client.com', 'invited_at' => now()]);
        $this->poll->participants()->create(['name' => 'No Email', 'responded_at' => now()]);
        $this->poll->participants()->create(['email' => 'pat@example.com', 'responded_at' => now()]); // the organizer
        $slot = $this->poll->slots->first();

        Livewire::actingAs($this->owner)->test(ManagePoll::class, ['poll' => $this->poll])
            ->call('finalize', $slot->id)
            ->assertSet('problem', null);

        $this->poll->refresh();
        $this->assertSame(Poll::STATUS_FINALIZED, $this->poll->status);
        $this->assertSame($slot->id, $this->poll->final_slot_id);
        $this->assertSame('evt_123', $this->poll->google_event_id);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'sendUpdates=all')
            && $request['attendees'] === [['email' => 'alex@client.com']]
            && $request['start']['dateTime'] === '2026-10-20T15:00:00+00:00'
            && $request['summary'] === 'Kickoff');
    }

    public function test_finalize_twice_only_creates_one_event(): void
    {
        Http::fake(['www.googleapis.com/calendar/*' => Http::response(['id' => 'evt_123'])]);
        $slot = $this->poll->slots->first();

        Livewire::actingAs($this->owner)->test(ManagePoll::class, ['poll' => $this->poll])
            ->call('finalize', $slot->id)
            ->call('finalize', $slot->id)
            ->assertSet('problem', 'This poll has already been finalized.');

        Http::assertSentCount(1);
    }

    public function test_calendar_failure_leaves_poll_open(): void
    {
        Http::fake(['www.googleapis.com/calendar/*' => Http::response(['error' => 'boom'], 500)]);

        Livewire::actingAs($this->owner)->test(ManagePoll::class, ['poll' => $this->poll])
            ->call('finalize', $this->poll->slots->first()->id)
            ->assertSet('problem', 'Google Calendar didn\'t accept the event. Nothing was sent. Please try again.');

        $this->assertTrue($this->poll->fresh()->isOpen());
    }

    public function test_disconnected_calendar_prompts_to_reconnect(): void
    {
        $this->owner->update(['google_refresh_token' => null]);

        Livewire::actingAs($this->owner)->test(ManagePoll::class, ['poll' => $this->poll])
            ->call('finalize', $this->poll->slots->first()->id)
            ->assertSet('problem', 'calendar_not_connected')
            ->assertSee('Connect Google Calendar');
    }

    public function test_results_grid_shows_votes(): void
    {
        $participant = $this->poll->participants()->create(['name' => 'Jordan', 'responded_at' => now()]);
        $participant->votes()->create(['poll_slot_id' => $this->poll->slots->first()->id, 'choice' => 'yes']);

        Livewire::actingAs($this->owner)->test(ManagePoll::class, ['poll' => $this->poll])
            ->assertSee('Jordan')
            ->assertSee('✓');
    }

    public function test_other_users_cannot_manage_the_poll(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('polls.show', $this->poll))
            ->assertNotFound();
    }
}
