<?php

namespace Tests\Feature;

use App\Exceptions\CalendarNotConnected;
use App\Models\User;
use App\Services\GoogleCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleCalendarTest extends TestCase
{
    use RefreshDatabase;

    private function connectedUser(): User
    {
        return User::factory()->create([
            'google_access_token' => 'access',
            'google_refresh_token' => 'refresh',
            'google_token_expires_at' => now()->addHour(),
        ]);
    }

    public function test_busy_blocks_skip_free_declined_cancelled_and_working_location_events(): void
    {
        Http::fake(['www.googleapis.com/calendar/*' => Http::response(['items' => [
            ['summary' => 'Standup', 'start' => ['dateTime' => '2026-10-19T09:00:00-05:00'], 'end' => ['dateTime' => '2026-10-19T09:30:00-05:00']],
            ['summary' => 'Free', 'transparency' => 'transparent', 'start' => ['dateTime' => '2026-10-19T10:00:00-05:00'], 'end' => ['dateTime' => '2026-10-19T11:00:00-05:00']],
            ['summary' => 'Declined', 'attendees' => [['email' => 'x', 'self' => true, 'responseStatus' => 'declined']], 'start' => ['dateTime' => '2026-10-19T12:00:00-05:00'], 'end' => ['dateTime' => '2026-10-19T13:00:00-05:00']],
            ['summary' => 'Cancelled', 'status' => 'cancelled', 'start' => ['dateTime' => '2026-10-19T14:00:00-05:00'], 'end' => ['dateTime' => '2026-10-19T15:00:00-05:00']],
            ['summary' => 'Home', 'eventType' => 'workingLocation', 'start' => ['date' => '2026-10-19'], 'end' => ['date' => '2026-10-20']],
            ['summary' => 'Offsite', 'start' => ['date' => '2026-10-20'], 'end' => ['date' => '2026-10-21']],
        ]])]);

        $blocks = app(GoogleCalendar::class)->busyBlocks(
            $this->connectedUser(),
            CarbonImmutable::parse('2026-10-19', 'America/Chicago'),
            CarbonImmutable::parse('2026-10-26', 'America/Chicago'),
        );

        $this->assertSame(['Standup', 'Offsite'], array_column($blocks, 'title'));
        $this->assertSame('2026-10-19T14:00:00Z', $blocks[0]['start']->toIso8601ZuluString());
        // All-day events cover the whole day in the user's timezone (CDT is UTC-5).
        $this->assertSame('2026-10-20T05:00:00Z', $blocks[1]['start']->toIso8601ZuluString());
        $this->assertSame('2026-10-21T05:00:00Z', $blocks[1]['end']->toIso8601ZuluString());
    }

    public function test_expired_access_token_is_refreshed(): void
    {
        $user = $this->connectedUser();
        $user->update(['google_token_expires_at' => now()->subMinute()]);

        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'new-access', 'expires_in' => 3599])]);

        $this->assertSame('new-access', app(GoogleCalendar::class)->accessToken($user));
        $this->assertSame('new-access', $user->fresh()->google_access_token);
    }

    public function test_revoked_access_clears_tokens(): void
    {
        $user = $this->connectedUser();
        $user->update(['google_token_expires_at' => now()->subMinute()]);

        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        try {
            app(GoogleCalendar::class)->accessToken($user);
            $this->fail('Expected CalendarNotConnected');
        } catch (CalendarNotConnected) {
            $this->assertFalse($user->fresh()->hasCalendarAccess());
        }
    }
}
