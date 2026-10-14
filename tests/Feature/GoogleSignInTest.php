<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GoogleCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Tests\TestCase;

class GoogleSignInTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGoogle(array $overrides = []): void
    {
        Socialite::fake('google', GoogleUser::fake(array_merge([
            'id' => '1001',
            'name' => 'Pat Example',
            'email' => 'pat@example.com',
            'hd' => 'example.com',
            'email_verified' => true,
            'approvedScopes' => ['openid', 'email', 'profile', GoogleCalendar::SCOPE],
        ], $overrides)));
    }

    public function test_company_account_signs_in_and_stores_calendar_tokens(): void
    {
        $this->fakeGoogle();

        $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));

        $user = User::sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('pat@example.com', $user->email);
        $this->assertSame('fake-refresh-token', $user->google_refresh_token);
        $this->assertTrue($user->hasCalendarAccess());
    }

    public function test_outside_accounts_are_rejected(): void
    {
        $this->fakeGoogle(['email' => 'someone@gmail.com', 'hd' => null]);

        $this->get('/auth/google/callback')->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_other_workspace_domains_are_rejected(): void
    {
        $this->fakeGoogle(['email' => 'pat@example.com.evil.io', 'hd' => 'example.com.evil.io']);

        $this->get('/auth/google/callback')->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_dev_allowlisted_gmail_can_sign_in_locally(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config(['meetingpoll.dev_allowed_emails' => ['me@gmail.com']]);
        $this->fakeGoogle(['email' => 'Me@gmail.com', 'hd' => null]);

        $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));

        $this->assertSame('me@gmail.com', User::sole()->email);
    }

    public function test_dev_allowlist_is_ignored_outside_local(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['meetingpoll.dev_allowed_emails' => ['me@gmail.com']]);
        $this->fakeGoogle(['email' => 'me@gmail.com', 'hd' => null]);

        $this->get('/auth/google/callback')->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_declining_calendar_access_still_signs_in_without_tokens(): void
    {
        $this->fakeGoogle(['approvedScopes' => ['openid', 'email', 'profile']]);

        $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));

        $this->assertFalse(User::sole()->hasCalendarAccess());
    }

    public function test_missing_refresh_token_sends_user_back_through_consent(): void
    {
        $this->fakeGoogle(['refreshToken' => null]);

        $this->get('/auth/google/callback')->assertRedirect(route('auth.google', ['consent' => 1]));
    }
}
