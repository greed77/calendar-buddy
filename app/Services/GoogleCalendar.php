<?php

namespace App\Services;

use App\Exceptions\CalendarNotConnected;
use App\Models\Poll;
use App\Models\PollSlot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the three Google Calendar calls this app needs:
 * refreshing a token, listing the user's events, and creating the final event.
 * Uses Laravel's HTTP client instead of google/apiclient, which is very large.
 */
class GoogleCalendar
{
    public const SCOPE = 'https://www.googleapis.com/auth/calendar.events';

    private const API = 'https://www.googleapis.com/calendar/v3';

    /**
     * Times the user is busy between $from and $to, from their primary calendar.
     *
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, title: string}>
     */
    public function busyBlocks(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $response = Http::withToken($this->accessToken($user))
            ->get(self::API.'/calendars/primary/events', [
                'timeMin' => $from->utc()->toRfc3339String(),
                'timeMax' => $to->utc()->toRfc3339String(),
                'singleEvents' => 'true',
                'orderBy' => 'startTime',
                'maxResults' => 250,
                'timeZone' => $user->timezone,
            ])
            ->throw();

        $blocks = [];

        foreach ($response->json('items', []) as $event) {
            if (! $this->blocksTime($event)) {
                continue;
            }

            // Timed events have start.dateTime; all-day events have start.date.
            $start = isset($event['start']['dateTime'])
                ? CarbonImmutable::parse($event['start']['dateTime'])
                : CarbonImmutable::parse($event['start']['date'], $user->timezone)->startOfDay();
            $end = isset($event['end']['dateTime'])
                ? CarbonImmutable::parse($event['end']['dateTime'])
                : CarbonImmutable::parse($event['end']['date'], $user->timezone)->startOfDay();

            $blocks[] = [
                'start' => $start->utc(),
                'end' => $end->utc(),
                'title' => $event['summary'] ?? 'Busy',
            ];
        }

        return $blocks;
    }

    /**
     * Create the meeting on the organizer's calendar and have Google email
     * invitations to every attendee, including people outside the company.
     *
     * @param  list<string>  $attendeeEmails
     * @return string The Google event id.
     */
    public function createEvent(User $organizer, Poll $poll, PollSlot $slot, array $attendeeEmails): string
    {
        $response = Http::withToken($this->accessToken($organizer))
            ->post(self::API.'/calendars/primary/events?sendUpdates=all', [
                'summary' => $poll->title,
                'description' => $poll->description,
                'location' => $poll->location,
                'start' => ['dateTime' => $slot->starts_at->toRfc3339String(), 'timeZone' => $poll->timezone],
                'end' => ['dateTime' => $slot->ends_at->toRfc3339String(), 'timeZone' => $poll->timezone],
                'attendees' => collect($attendeeEmails)
                    ->unique()
                    ->map(fn (string $email) => ['email' => $email])
                    ->values()
                    ->all(),
            ])
            ->throw();

        return $response->json('id');
    }

    /** A valid access token for the user, refreshing it if it is about to expire. */
    public function accessToken(User $user): string
    {
        if (! $user->hasCalendarAccess()) {
            throw new CalendarNotConnected;
        }

        if ($user->google_access_token && $user->google_token_expires_at?->isAfter(now()->addMinute())) {
            return $user->google_access_token;
        }

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $user->google_refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if ($response->json('error') === 'invalid_grant') {
            // Access was revoked or the refresh token expired; the user must reconnect.
            $user->update(['google_refresh_token' => null, 'google_access_token' => null]);

            throw new CalendarNotConnected;
        }

        $response->throw();

        $user->update([
            'google_access_token' => $response->json('access_token'),
            'google_token_expires_at' => now()->addSeconds($response->json('expires_in', 3600)),
        ]);

        return $user->google_access_token;
    }

    /** Skip cancelled, "show as free", declined, and working-location events. */
    private function blocksTime(array $event): bool
    {
        if (($event['status'] ?? null) === 'cancelled') {
            return false;
        }

        if (($event['transparency'] ?? 'opaque') === 'transparent') {
            return false;
        }

        if (($event['eventType'] ?? 'default') === 'workingLocation') {
            return false;
        }

        foreach ($event['attendees'] ?? [] as $attendee) {
            if (($attendee['self'] ?? false) && ($attendee['responseStatus'] ?? null) === 'declined') {
                return false;
            }
        }

        return true;
    }
}
