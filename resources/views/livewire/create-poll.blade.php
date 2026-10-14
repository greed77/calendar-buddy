<div x-data x-init="
    const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
    if (tz && tz !== $wire.timezone) $wire.setTimezone(tz);
">
    <h1 class="text-2xl font-semibold">New poll</h1>

    <form wire:submit="save" class="mt-6 grid gap-8 lg:grid-cols-[18rem_1fr]">
        {{-- Details --}}
        <div class="space-y-4">
            <div>
                <label for="title" class="block text-sm font-medium">Title</label>
                <input id="title" type="text" wire:model="title" required
                       class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-zinc-500 focus:outline-none">
                @error('title') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="duration" class="block text-sm font-medium">Meeting length</label>
                <select id="duration" wire:model.live="duration"
                        class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2 text-sm">
                    @foreach (config('meetingpoll.durations') as $minutes)
                        <option value="{{ $minutes }}">{{ $minutes < 60 ? "$minutes min" : ($minutes / 60).' hr' }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="location" class="block text-sm font-medium">Location <span class="font-normal text-zinc-500">(optional)</span></label>
                <input id="location" type="text" wire:model="location" placeholder="Room, address, or video link"
                       class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2 text-sm">
            </div>

            <div>
                <label for="description" class="block text-sm font-medium">Description <span class="font-normal text-zinc-500">(optional)</span></label>
                <textarea id="description" wire:model="description" rows="4"
                          class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2 text-sm"></textarea>
            </div>

            <div>
                <h2 class="text-sm font-medium">Options ({{ count($options) }})</h2>
                @error('options') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                <ul class="mt-2 space-y-1 text-sm">
                    @forelse ($selected as $i => $slot)
                        <li class="flex items-center justify-between rounded border border-zinc-200 bg-white px-2 py-1">
                            <span>{{ $slot->format('D M j') }}, {{ $slot->format('g:i A') }}–{{ $slot->addMinutes($duration)->format('g:i A') }}</span>
                            <button type="button" wire:click="removeSlot('{{ $options[$i] }}')" class="text-zinc-400 hover:text-red-600" aria-label="Remove">&times;</button>
                        </li>
                    @empty
                        <li class="text-zinc-500">Click times on the grid to add options.</li>
                    @endforelse
                </ul>
            </div>

            <button type="submit" class="w-full rounded-md bg-zinc-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-zinc-700">
                Create poll
            </button>
        </div>

        {{-- Week grid --}}
        <div>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <button type="button" wire:click="previousWeek" class="rounded border border-zinc-300 bg-white px-2 py-1 text-sm hover:bg-zinc-50">&larr;</button>
                    <button type="button" wire:click="nextWeek" class="rounded border border-zinc-300 bg-white px-2 py-1 text-sm hover:bg-zinc-50">&rarr;</button>
                    <span class="text-sm font-medium">Week of {{ $days->first()->format('M j, Y') }}</span>
                </div>
                <span class="text-xs text-zinc-500">Times in {{ str_replace('_', ' ', $timezone) }}</span>
            </div>

            @if ($this->busy['error'] === 'not_connected')
                <p class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    Your calendar isn't connected, so busy times aren't shown.
                    <a href="{{ route('auth.google', ['consent' => 1]) }}" class="font-medium underline">Connect Google Calendar</a>
                </p>
            @elseif ($this->busy['error'] === 'failed')
                <p class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    Couldn't load your calendar right now. Busy times aren't shown.
                </p>
            @endif

            <div class="mt-3 flex gap-4 text-xs text-zinc-600">
                <span class="flex items-center gap-1"><span class="inline-block h-3 w-3 rounded-sm bg-amber-200"></span> Busy on your calendar</span>
                <span class="flex items-center gap-1"><span class="inline-block h-3 w-3 rounded-sm bg-indigo-500"></span> Option you picked</span>
            </div>

            <div class="mt-2 overflow-x-auto rounded-lg border border-zinc-200 bg-white">
                <table class="w-full min-w-[42rem] table-fixed border-collapse text-xs">
                    <thead>
                        <tr>
                            <th class="w-16"></th>
                            @foreach ($days as $day)
                                <th class="border-b border-l border-zinc-200 px-1 py-2 font-medium {{ $day->isToday() ? 'text-indigo-600' : '' }}">
                                    {{ $day->format('D') }}<br><span class="text-zinc-500">{{ $day->format('M j') }}</span>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody wire:loading.class="opacity-60" wire:target="previousWeek,nextWeek,setTimezone">
                        @foreach ($grid as $row)
                            <tr>
                                <td class="pr-2 text-right align-top text-zinc-500 {{ str_ends_with($row['label'], ':00 AM') || str_ends_with($row['label'], ':00 PM') ? '' : 'text-transparent' }}">
                                    {{ $row['label'] }}
                                </td>
                                @foreach ($row['cells'] as $cell)
                                    @php
                                        $classes = match (true) {
                                            $cell['selected'] => 'bg-indigo-500 text-white hover:bg-indigo-600',
                                            $cell['past'] => 'bg-zinc-100 cursor-not-allowed',
                                            $cell['busy'] => 'bg-amber-200 text-amber-900 hover:bg-amber-300',
                                            default => 'hover:bg-indigo-50',
                                        };
                                    @endphp
                                    <td class="h-6 border-l border-zinc-200 p-0 {{ str_ends_with($row['label'], ':00 AM') || str_ends_with($row['label'], ':00 PM') ? 'border-t' : 'border-t border-t-zinc-100' }}">
                                        <button type="button"
                                                wire:click="toggle('{{ $cell['start'] }}')"
                                                @disabled($cell['past'] && ! $cell['selected'])
                                                title="{{ $cell['busyTitle'] }}"
                                                class="block h-6 w-full truncate px-1 text-left leading-6 {{ $classes }}">
                                            @if ($cell['slotStart'])
                                                {{ $cell['busy'] ? '⚠ ' : '' }}{{ \Carbon\CarbonImmutable::parse($cell['start'])->setTimezone($timezone)->format('g:i') }}
                                            @elseif ($cell['busyLabel'] && ! $cell['selected'])
                                                {{ $cell['busyTitle'] }}
                                            @endif
                                        </button>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-zinc-500">
                Click a time to add an option starting then; click an option again to remove it.
                You can still pick a busy time. It gets a ⚠ so you can see the conflict.
            </p>
        </div>
    </form>
</div>
