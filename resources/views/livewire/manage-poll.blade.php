<div>
    <a href="{{ route('dashboard') }}" class="text-sm text-zinc-500 hover:text-zinc-900">&larr; Your polls</a>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">{{ $poll->title }}</h1>
            <p class="mt-1 text-sm text-zinc-600">
                {{ $poll->duration_minutes }} min
                @if ($poll->location) · {{ $poll->location }} @endif
            </p>
        </div>
        <span class="rounded-full px-2.5 py-0.5 text-xs font-medium capitalize {{ $poll->isOpen() ? 'bg-zinc-100 text-zinc-700' : 'bg-emerald-100 text-emerald-800' }}">{{ $poll->status }}</span>
    </div>

    @if ($poll->description)
        <p class="mt-4 whitespace-pre-line text-sm text-zinc-700">{{ $poll->description }}</p>
    @endif

    @if ($notice)
        <div class="mt-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ $notice }}</div>
    @endif
    @if ($problem === 'calendar_not_connected')
        <div class="mt-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            Your Google Calendar isn't connected, so the event couldn't be created.
            <a href="{{ route('auth.google', ['consent' => 1]) }}" class="font-medium underline">Connect Google Calendar</a>, then pick the time again.
        </div>
    @elseif ($problem)
        <div class="mt-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $problem }}</div>
    @endif

    @if ($poll->finalSlot)
        @php($final = $poll->finalSlot->starts_at->setTimezone($timezone))
        <div class="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 px-5 py-4">
            <p class="text-sm font-medium text-emerald-800">Scheduled</p>
            <p class="mt-1 text-lg font-semibold text-emerald-900">
                {{ $final->format('l, F j') }} · {{ $final->format('g:i A') }}–{{ $poll->finalSlot->ends_at->setTimezone($timezone)->format('g:i A') }}
            </p>
            <p class="mt-1 text-sm text-emerald-800">It's on your Google Calendar. Change or cancel it there.</p>
        </div>
    @endif

    @if ($poll->isOpen())
        <div class="mt-8 grid gap-6 md:grid-cols-2">
            {{-- Share link --}}
            <div class="rounded-lg border border-zinc-200 bg-white p-4" x-data="{ copied: false }">
                <h2 class="text-sm font-medium">Share link</h2>
                <p class="mt-1 text-xs text-zinc-500">Anyone with this link can answer without signing in.</p>
                <div class="mt-3 flex gap-2">
                    <input type="text" readonly value="{{ $poll->shareUrl() }}" x-ref="link"
                           class="min-w-0 flex-1 rounded-md border border-zinc-300 bg-zinc-50 px-3 py-2 font-mono text-xs">
                    <button type="button"
                            x-on:click="navigator.clipboard.writeText($refs.link.value); copied = true; setTimeout(() => copied = false, 2000)"
                            class="rounded-md border border-zinc-300 px-3 py-2 text-sm hover:bg-zinc-50">
                        <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                    </button>
                </div>
                <a href="{{ $poll->shareUrl() }}" target="_blank" class="mt-2 inline-block text-xs text-zinc-500 underline hover:text-zinc-900">Answer it yourself</a>
            </div>

            {{-- Invite by email --}}
            <form wire:submit="invite" class="rounded-lg border border-zinc-200 bg-white p-4">
                <label for="invitees" class="text-sm font-medium">Invite by email</label>
                <p class="mt-1 text-xs text-zinc-500">Each person gets their own link. Separate addresses with commas or new lines.</p>
                <textarea id="invitees" wire:model="invitees" rows="2" placeholder="alex@example.com, sam@client.com"
                          class="mt-3 w-full rounded-md border border-zinc-300 px-3 py-2 text-sm"></textarea>
                @error('invitees') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                <button type="submit" wire:loading.attr="disabled" wire:target="invite"
                        class="mt-2 rounded-md bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 disabled:opacity-60">
                    Send invitations
                </button>
            </form>
        </div>
    @endif

    {{-- Results --}}
    <div class="mt-8 flex items-baseline justify-between">
        <h2 class="text-sm font-medium">Responses</h2>
        <span class="text-xs text-zinc-500">Times in {{ str_replace('_', ' ', $timezone) }}</span>
    </div>

    <div class="mt-2 overflow-x-auto rounded-lg border border-zinc-200 bg-white">
        <table class="w-full border-collapse text-sm">
            <thead>
                <tr class="border-b border-zinc-200">
                    <th class="sticky left-0 min-w-44 bg-white px-4 py-2 text-left font-medium">Participant</th>
                    @foreach ($poll->slots as $slot)
                        @php($start = $slot->starts_at->setTimezone($timezone))
                        <th class="min-w-24 border-l border-zinc-200 px-2 py-2 text-center font-medium {{ in_array($slot->id, $bestSlotIds) ? 'bg-emerald-50' : '' }} {{ $poll->final_slot_id === $slot->id ? 'bg-emerald-100' : '' }}">
                            <div class="text-xs text-zinc-500">{{ $start->format('D M j') }}</div>
                            <div>{{ $start->format('g:i A') }}</div>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($poll->participants as $participant)
                    <tr class="border-b border-zinc-100" wire:key="participant-{{ $participant->id }}">
                        <td class="sticky left-0 bg-white px-4 py-2">
                            <div class="font-medium">{{ $participant->name ?? $participant->email }}</div>
                            @if ($participant->name && $participant->email)
                                <div class="text-xs text-zinc-500">{{ $participant->email }}</div>
                            @endif
                            @unless ($participant->responded_at)
                                <div class="text-xs text-amber-700">Invited, no answer yet</div>
                            @endunless
                        </td>
                        @foreach ($poll->slots as $slot)
                            @php($choice = $votes[$participant->id][$slot->id] ?? null)
                            <td class="border-l border-zinc-200 text-center {{ match ($choice) { 'yes' => 'bg-emerald-100 text-emerald-800', 'maybe' => 'bg-amber-100 text-amber-800', 'no' => 'text-zinc-400', default => 'text-zinc-300' } }}">
                                {{ match ($choice) { 'yes' => '✓', 'maybe' => '(✓)', 'no' => '✗', default => '·' } }}
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $poll->slots->count() + 1 }}" class="px-4 py-8 text-center text-zinc-500">No one has been invited or answered yet.</td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr class="bg-zinc-50">
                    <td class="sticky left-0 bg-zinc-50 px-4 py-2 text-xs font-medium text-zinc-600">Yes / if need be</td>
                    @foreach ($poll->slots as $slot)
                        <td class="border-l border-zinc-200 px-2 py-2 text-center">
                            <div class="font-medium">{{ $scores[$slot->id]['yes'] }} <span class="text-zinc-400">/</span> {{ $scores[$slot->id]['maybe'] }}</div>
                            @if ($poll->isOpen())
                                <button type="button"
                                        wire:click="finalize({{ $slot->id }})"
                                        wire:confirm="Schedule {{ $slot->starts_at->setTimezone($timezone)->format('D M j, g:i A') }}? This creates the event on your Google Calendar and emails an invite to {{ $attendeeCount }} {{ Str::plural('person', $attendeeCount) }}."
                                        wire:loading.attr="disabled"
                                        class="mt-1 rounded border border-zinc-300 bg-white px-2 py-0.5 text-xs hover:bg-zinc-100 disabled:opacity-50">
                                    Pick
                                </button>
                            @endif
                        </td>
                    @endforeach
                </tr>
            </tfoot>
        </table>
    </div>
    <p class="mt-2 text-xs text-zinc-500">Shaded green: the most popular time so far. People without an email address won't receive the calendar invite.</p>

    <div class="mt-12 border-t border-zinc-200 pt-6">
        <button type="button" wire:click="delete"
                wire:confirm="Delete this poll and all its answers?{{ $poll->finalSlot ? ' The calendar event stays on your calendar.' : '' }}"
                class="text-sm text-red-600 hover:text-red-800">Delete poll</button>
    </div>
</div>
