<x-layouts.app title="Sign in">
    <div class="mx-auto mt-16 max-w-sm rounded-lg border border-zinc-200 bg-white p-8 text-center shadow-sm">
        <h1 class="text-xl font-semibold">Sign in</h1>
        <p class="mt-2 text-sm text-zinc-600">
            Use your {{ config('meetingpoll.allowed_domain') }} Google account. We'll ask for calendar access so
            your busy times show up when you pick slots.
        </p>
        <a href="{{ route('auth.google') }}"
           class="mt-6 inline-flex w-full items-center justify-center rounded-md bg-zinc-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-zinc-700">
            Sign in with Google
        </a>
    </div>
</x-layouts.app>
