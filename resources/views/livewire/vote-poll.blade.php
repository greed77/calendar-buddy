<div class="mx-auto max-w-2xl" x-data x-init="
    const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
    if (tz && tz !== $wire.timezone) $wire.setTimezone(tz);
">
    <p class="text-sm text-zinc-500">{{ $poll->user->name }} is looking for a time</p>
    <h1 class="mt-1 text-2xl font-semibold">{{ $poll->title }}</h1>
    <p class="mt-1 text-sm text-zinc-600">
        {{ $poll->duration_minutes }} min
        @if ($poll->location) · {{ $poll->location }} @endif
    </p>
    @if ($poll->description)
        <p class="mt-4 whitespace-pre-line text-sm text-zinc-700">{{ $poll->description }}</p>
    @endif

    @if ($poll->finalSlot)
        @php($final = $poll->finalSlot->starts_at->setTimezone($timezone))
        <div class="mt-8 rounded-lg border border-emerald-200 bg-emerald-50 px-5 py-4">
            <p class="text-sm font-medium text-emerald-800">This meeting has been scheduled</p>
            <p class="mt-1 text-lg font-semibold text-emerald-900">
                {{ $final->format('l, F j') }} · {{ $final->format('g:i A') }}–{{ $poll->finalSlot->ends_at->setTimezone($timezone)->format('g:i A') }}
            </p>
            <p class="mt-1 text-sm text-emerald-800">If you left an email address, a calendar invitation is on its way.</p>
        </div>
    @elseif (! $poll->isOpen())
        <div class="mt-8 rounded-lg border border-zinc-200 bg-white px-5 py-4 text-sm text-zinc-600">This poll is closed.</div>
    @else
        @if ($saved)
            <div class="mt-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                <p class="font-medium">Thanks, your answers are saved.</p>
                @if ($personalUrl)
                    <p class="mt-1">To change them later, use this link (bookmark it, or it's in your invitation email):</p>
                    <p class="mt-1 break-all font-mono text-xs">{{ $personalUrl }}</p>
                @endif
            </div>
        @endif

        @error('poll') <div class="mt-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</div> @enderror

        <form wire:submit="submit" class="mt-8 space-y-6">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="name" class="block text-sm font-medium">Your name</label>
                    <input id="name" type="text" wire:model="name" required autocomplete="name"
                           class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2 text-sm">
                    @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="email" class="block text-sm font-medium">
                        Email @unless ($emailLocked) <span class="font-normal text-zinc-500">(to get the calendar invite)</span> @endunless
                    </label>
                    <input id="email" type="email" wire:model="email" autocomplete="email" @disabled($emailLocked)
                           class="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2 text-sm disabled:bg-zinc-100 disabled:text-zinc-500">
                    @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <div class="flex items-baseline justify-between">
                    <h2 class="text-sm font-medium">When can you make it?</h2>
                    <span class="text-xs text-zinc-500">Times in {{ str_replace('_', ' ', $timezone) }}</span>
                </div>

                <div class="mt-2 space-y-4">
                    @foreach ($days as $date => $daySlots)
                        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white">
                            <div class="border-b border-zinc-200 bg-zinc-50 px-4 py-2 text-sm font-medium">
                                {{ \Carbon\CarbonImmutable::parse($date)->format('l, F j') }}
                            </div>
                            <ul class="divide-y divide-zinc-100">
                                @foreach ($daySlots as $slot)
                                    <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3" wire:key="slot-{{ $slot->id }}">
                                        <div>
                                            <div class="text-sm font-medium">
                                                {{ $slot->starts_at->setTimezone($timezone)->format('g:i A') }}–{{ $slot->ends_at->setTimezone($timezone)->format('g:i A') }}
                                            </div>
                                            @if ($slot->yes_count || $slot->maybe_count)
                                                <div class="text-xs text-zinc-500">
                                                    {{ $slot->yes_count }} yes @if ($slot->maybe_count) · {{ $slot->maybe_count }} if need be @endif
                                                </div>
                                            @endif
                                        </div>

                                        {{-- Radios update locally; nothing is sent until "Save answers". --}}
                                        <div class="flex overflow-hidden rounded-md border border-zinc-300 text-sm" role="radiogroup">
                                            @foreach (['yes' => ['Yes', 'peer-checked:bg-emerald-600'], 'maybe' => ['If need be', 'peer-checked:bg-amber-500'], 'no' => ['No', 'peer-checked:bg-zinc-600']] as $value => [$label, $checked])
                                                <label class="relative">
                                                    <input type="radio" wire:model="choices.{{ $slot->id }}" value="{{ $value }}" class="peer sr-only">
                                                    <span class="block cursor-pointer px-3 py-1.5 hover:bg-zinc-50 peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-indigo-500 {{ $checked }} {{ $value !== 'yes' ? 'border-l border-zinc-300' : '' }}">{{ $label }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
                <p class="mt-2 text-xs text-zinc-500">Any time you leave unanswered counts as "No".</p>
            </div>

            <button type="submit" wire:loading.attr="disabled"
                    class="w-full rounded-md bg-zinc-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-zinc-700 disabled:opacity-60 sm:w-auto">
                {{ $participantId ? 'Update answers' : 'Save answers' }}
            </button>
        </form>
    @endif
</div>
