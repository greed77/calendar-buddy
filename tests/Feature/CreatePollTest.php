<?php

namespace Tests\Feature;

use App\Livewire\CreatePoll;
use App\Models\Poll;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class CreatePollTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday 2026-10-14, 8:00 AM Chicago.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 08:00', 'America/Chicago'));
    }

    public function test_creates_poll_with_selected_slots(): void
    {
        $user = User::factory()->create(['timezone' => 'America/Chicago']);

        Livewire::actingAs($user)->test(CreatePoll::class)
            ->set('title', 'Kickoff')
            ->set('duration', 60)
            ->call('toggle', '2026-10-15T15:00:00Z')  // Thu 10:00 AM CDT
            ->call('toggle', '2026-10-16T19:30:00Z')  // Fri 2:30 PM CDT
            ->call('save')
            ->assertHasNoErrors();

        $poll = Poll::sole();
        $this->assertSame('Kickoff', $poll->title);
        $this->assertSame('America/Chicago', $poll->timezone);
        $this->assertSame(
            ['2026-10-15T15:00:00Z', '2026-10-16T19:30:00Z'],
            $poll->slots->map(fn ($s) => $s->starts_at->toIso8601ZuluString())->all(),
        );
        $this->assertSame('2026-10-15T16:00:00Z', $poll->slots[0]->ends_at->toIso8601ZuluString());
    }

    public function test_clicking_inside_a_slot_removes_it(): void
    {
        Livewire::actingAs(User::factory()->create())->test(CreatePoll::class)
            ->call('toggle', '2026-10-15T15:00:00Z')
            ->call('toggle', '2026-10-15T15:30:00Z') // second half hour of the same 60-min slot
            ->assertSet('options', []);
    }

    public function test_rejects_overlapping_and_past_slots(): void
    {
        Livewire::actingAs(User::factory()->create())->test(CreatePoll::class)
            ->set('duration', 60)
            ->call('toggle', '2026-10-15T15:00:00Z')
            ->call('toggle', '2026-10-15T14:30:00Z') // would run into the 15:00 slot
            ->assertHasErrors('options')
            ->call('toggle', '2026-10-13T15:00:00Z') // yesterday
            ->assertSet('options', ['2026-10-15T15:00:00Z']);
    }

    public function test_requires_at_least_one_slot(): void
    {
        Livewire::actingAs(User::factory()->create())->test(CreatePoll::class)
            ->set('title', 'Kickoff')
            ->call('save')
            ->assertHasErrors('options');
    }

    public function test_grid_shades_busy_times_from_calendar(): void
    {
        Http::fake(['www.googleapis.com/calendar/*' => Http::response(['items' => [
            ['summary' => 'Dentist', 'start' => ['dateTime' => '2026-10-15T09:00:00-05:00'], 'end' => ['dateTime' => '2026-10-15T10:00:00-05:00']],
        ]])]);

        $user = User::factory()->create([
            'timezone' => 'America/Chicago',
            'google_access_token' => 'access',
            'google_refresh_token' => 'refresh',
            'google_token_expires_at' => now()->addHour(),
        ]);

        Livewire::actingAs($user)->test(CreatePoll::class)
            ->assertSee('Dentist')
            ->assertDontSee('Calendar isn');
    }

    public function test_only_the_owner_can_view_a_poll(): void
    {
        $owner = User::factory()->create();
        $poll = $owner->polls()->create(['title' => 'Private', 'duration_minutes' => 30, 'timezone' => 'UTC']);

        $this->actingAs($owner)->get(route('polls.show', $poll))->assertOk()->assertSee('Private');
        $this->actingAs(User::factory()->create())->get(route('polls.show', $poll))->assertNotFound();
    }
}
