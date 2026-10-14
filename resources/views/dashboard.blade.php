<x-layouts.app title="Your polls">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-semibold">Your polls</h1>
        <a href="{{ route('polls.create') }}" class="rounded-md bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700">New poll</a>
    </div>

    @unless (auth()->user()->hasCalendarAccess())
        <div class="mt-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Calendar isn't connected, so your busy times won't show when picking slots.
            <a href="{{ route('auth.google', ['consent' => 1]) }}" class="font-medium underline">Connect Google Calendar</a>
        </div>
    @endunless

    <div class="mt-6 divide-y divide-zinc-200 rounded-lg border border-zinc-200 bg-white">
        @forelse ($polls as $poll)
            <a href="{{ route('polls.show', $poll) }}" class="flex items-center justify-between px-4 py-3 hover:bg-zinc-50">
                <div>
                    <div class="font-medium">{{ $poll->title }}</div>
                    <div class="text-sm text-zinc-500">
                        {{ $poll->slots_count }} {{ Str::plural('option', $poll->slots_count) }}
                        · {{ $poll->responses_count }} {{ Str::plural('response', $poll->responses_count) }}
                        · {{ $poll->duration_minutes }} min
                        · created {{ $poll->created_at->diffForHumans() }}
                    </div>
                </div>
                <span class="rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs font-medium capitalize text-zinc-700">{{ $poll->status }}</span>
            </a>
        @empty
            <div class="px-4 py-10 text-center text-sm text-zinc-500">No polls yet.</div>
        @endforelse
    </div>
</x-layouts.app>
