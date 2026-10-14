<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GoogleCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

class GoogleController extends Controller
{
    /**
     * Send the user to Google. Pass ?consent=1 to force the consent screen,
     * which is the only way Google re-issues a refresh token.
     */
    public function redirect(Request $request): RedirectResponse
    {
        return Socialite::driver('google')
            ->scopes([GoogleCalendar::SCOPE])
            ->with(array_filter([
                // hd narrows Google's account chooser to the company domain; drop it when
                // dev accounts (e.g. a personal Gmail) are allowed so they can be picked.
                'hd' => $this->devAllowedEmails() ? null : config('meetingpoll.allowed_domain'),
                'access_type' => 'offline',
                'include_granted_scopes' => 'true',
                'prompt' => $request->boolean('consent') ? 'consent select_account' : 'select_account',
            ]))
            ->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->has('error')) {
            return redirect()->route('login')->with('error', 'Google sign-in was cancelled.');
        }

        try {
            $google = Socialite::driver('google')->user();
        } catch (InvalidStateException) {
            return redirect()->route('login')->with('error', 'Your sign-in session expired. Please try again.');
        }

        // The hd parameter only pre-selects the domain in Google's UI; enforce it here.
        $domain = Str::lower(config('meetingpoll.allowed_domain'));
        $email = Str::lower($google->getEmail());

        $companyAccount = ($google->user['hd'] ?? null) === $domain && Str::endsWith($email, '@'.$domain);
        $devAccount = in_array($email, $this->devAllowedEmails(), true);

        if (! ($companyAccount || $devAccount) || ! ($google->user['email_verified'] ?? false)) {
            return redirect()->route('login')->with('error', "Only {$domain} accounts can sign in.");
        }

        $user = User::updateOrCreate(
            ['google_id' => $google->getId()],
            ['name' => $google->getName(), 'email' => $email, 'avatar_url' => $google->getAvatar()],
        );

        // People can untick the calendar permission on Google's consent screen.
        $grantedCalendar = in_array(GoogleCalendar::SCOPE, $google->approvedScopes ?? [], true);

        if ($grantedCalendar) {
            $user->google_access_token = $google->token;
            $user->google_token_expires_at = now()->addSeconds($google->expiresIn ?? 3600);

            // Google only sends a refresh token from the consent screen, so keep the old one otherwise.
            if ($google->refreshToken) {
                $user->google_refresh_token = $google->refreshToken;
            }

            $user->save();
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        // First sign-in, or access was revoked: go back through consent to get a refresh token.
        if ($grantedCalendar && ! $user->hasCalendarAccess()) {
            return redirect()->route('auth.google', ['consent' => 1]);
        }

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /** Individual test accounts allowed to sign in, only ever in local development. */
    private function devAllowedEmails(): array
    {
        return app()->environment('local') ? config('meetingpoll.dev_allowed_emails') : [];
    }
}
