<?php

namespace App\Livewire;

use App\Exceptions\CalendarNotConnected;
use App\Models\Poll;
use App\Services\GoogleCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('New poll')]
class CreatePoll extends Component
{
    private const CELL_MINUTES = 30;

    public string $title = '';

    public string $description = '';

    public string $location = '';

    public int $duration = 60;

    /** Creator's timezone; the grid is drawn in it. Detected from the browser. */
    public string $timezone = 'UTC';

    /** Monday of the week shown in the grid (Y-m-d, in $timezone). */
    public string $weekStart = '';

    /** Selected slot start times, as UTC ISO-8601 strings. */
    public array $options = [];

    public function mount(): void
    {
        $this->timezone = auth()->user()->timezone;
        $this->weekStart = $this->currentWeekStart()->toDateString();
    }

    public function setTimezone(string $timezone): void
    {
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            return;
        }

        $this->timezone = $timezone;
        $this->weekStart = $this->currentWeekStart()->toDateString();
        auth()->user()->update(['timezone' => $timezone]);
    }

    public function previousWeek(): void
    {
        $previous = $this->week()->subWeek();

        if ($previous->gte($this->currentWeekStart())) {
            $this->weekStart = $previous->toDateString();
        }
    }

    public function nextWeek(): void
    {
        $this->weekStart = $this->week()->addWeek()->toDateString();
    }

    /** Click on a grid cell: remove the slot covering it, or add a slot starting there. */
    public function toggle(string $cellStart): void
    {
        $start = CarbonImmutable::parse($cellStart)->utc();

        foreach ($this->options as $i => $slot) {
            $slotStart = CarbonImmutable::parse($slot);

            if ($start->gte($slotStart) && $start->lt($slotStart->addMinutes($this->duration))) {
                unset($this->options[$i]);
                $this->options = array_values($this->options);

                return;
            }
        }

        if ($start->isPast()) {
            return;
        }

        if ($this->overlapsExisting($start)) {
            $this->addError('options', 'That time overlaps an option you already picked.');

            return;
        }

        $this->resetErrorBag('options');
        $this->options[] = $start->toIso8601ZuluString();
        sort($this->options);
    }

    public function removeSlot(string $slot): void
    {
        $this->options = array_values(array_diff($this->options, [$slot]));
    }

    public function save()
    {
        $this->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:255'],
            'duration' => ['required', 'integer', Rule::in(config('meetingpoll.durations'))],
            'options' => ['required', 'array', 'min:1', 'max:100'],
        ], ['options.required' => 'Pick at least one time on the grid.']);

        $starts = collect($this->options)->map(fn ($s) => CarbonImmutable::parse($s)->utc())->sort()->values();

        // Changing the duration after picking can make neighbouring options overlap.
        foreach ($starts as $i => $start) {
            if ($i > 0 && $starts[$i - 1]->addMinutes($this->duration)->gt($start)) {
                $this->addError('options', 'Some options overlap at this meeting length. Remove one of them.');

                return;
            }
        }

        $poll = DB::transaction(function () use ($starts) {
            $poll = auth()->user()->polls()->create([
                'title' => $this->title,
                'description' => $this->description ?: null,
                'location' => $this->location ?: null,
                'duration_minutes' => $this->duration,
                'timezone' => $this->timezone,
            ]);

            $poll->slots()->createMany($starts->map(fn (CarbonImmutable $start) => [
                'starts_at' => $start,
                'ends_at' => $start->addMinutes($this->duration),
            ]));

            return $poll;
        });

        return redirect()->route('polls.show', $poll)->with('status', 'Poll created.');
    }

    /**
     * The creator's busy times for the displayed week, cached briefly so that
     * clicking around the grid doesn't call Google on every request.
     *
     * @return array{blocks: list<array{start: CarbonImmutable, end: CarbonImmutable, title: string}>, error: ?string}
     */
    #[Computed]
    public function busy(): array
    {
        $user = auth()->user();

        if (! $user->hasCalendarAccess()) {
            return ['blocks' => [], 'error' => 'not_connected'];
        }

        $key = "busy:{$user->id}:{$this->timezone}:{$this->weekStart}";

        try {
            // The cache only stores plain values (no objects), so keep ISO strings in it.
            $cached = Cache::remember($key, now()->addMinutes(5), fn () => collect(
                app(GoogleCalendar::class)->busyBlocks($user, $this->week(), $this->week()->addWeek())
            )->map(fn ($b) => [
                'start' => $b['start']->toIso8601ZuluString(),
                'end' => $b['end']->toIso8601ZuluString(),
                'title' => $b['title'],
            ])->all());

            $blocks = array_map(fn ($b) => [
                'start' => CarbonImmutable::parse($b['start']),
                'end' => CarbonImmutable::parse($b['end']),
                'title' => $b['title'],
            ], $cached);

            return ['blocks' => $blocks, 'error' => null];
        } catch (CalendarNotConnected) {
            return ['blocks' => [], 'error' => 'not_connected'];
        } catch (RequestException $e) {
            Log::warning('Google Calendar request failed', ['user' => $user->id, 'error' => $e->getMessage()]);

            return ['blocks' => [], 'error' => 'failed'];
        }
    }

    public function render()
    {
        return view('livewire.create-poll', [
            'days' => collect(range(0, 6))->map(fn ($i) => $this->week()->addDays($i)),
            'grid' => $this->buildGrid(),
            'selected' => collect($this->options)->map(fn ($s) => CarbonImmutable::parse($s)->setTimezone($this->timezone)),
        ]);
    }

    /**
     * One row per half hour; each cell knows whether it is past, busy, or inside a picked option.
     *
     * @return list<array{label: string, cells: list<array<string, mixed>>}>
     */
    private function buildGrid(): array
    {
        $busy = $this->busy['blocks'];
        $slots = collect($this->options)->map(fn ($s) => CarbonImmutable::parse($s));
        $rows = [];

        $startMinute = config('meetingpoll.grid_start_hour') * 60;
        $endMinute = config('meetingpoll.grid_end_hour') * 60;

        for ($minute = $startMinute; $minute < $endMinute; $minute += self::CELL_MINUTES) {
            $cells = [];

            for ($day = 0; $day < 7; $day++) {
                $start = $this->week()->addDays($day)->setTime(intdiv($minute, 60), $minute % 60)->utc();
                $end = $start->addMinutes(self::CELL_MINUTES);

                $busyIndex = collect($busy)->search(fn ($b) => $b['start']->lt($end) && $b['end']->gt($start));
                $slot = $slots->first(fn ($s) => $start->gte($s) && $start->lt($s->addMinutes($this->duration)));

                $cells[] = [
                    'start' => $start->toIso8601ZuluString(),
                    'past' => $start->isPast(),
                    'busy' => $busyIndex !== false,
                    'busyTitle' => $busyIndex !== false ? $busy[$busyIndex]['title'] : null,
                    'busyIndex' => $busyIndex,
                    'selected' => $slot !== null,
                    'slotStart' => $slot !== null && $slot->eq($start),
                ];
            }

            $rows[] = [
                'label' => $this->week()->setTime(intdiv($minute, 60), $minute % 60)->format('g:i A'),
                'cells' => $cells,
            ];
        }

        // Only label the first cell of each busy block, not every half hour of it.
        foreach ($rows as $r => $row) {
            foreach ($row['cells'] as $d => $cell) {
                $rows[$r]['cells'][$d]['busyLabel'] = $cell['busy']
                    && ($r === 0 || $rows[$r - 1]['cells'][$d]['busyIndex'] !== $cell['busyIndex']);
            }
        }

        return $rows;
    }

    private function overlapsExisting(CarbonImmutable $start): bool
    {
        $end = $start->addMinutes($this->duration);

        return collect($this->options)->contains(function ($slot) use ($start, $end) {
            $slotStart = CarbonImmutable::parse($slot);

            return $slotStart->lt($end) && $start->lt($slotStart->addMinutes($this->duration));
        });
    }

    private function week(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->weekStart, $this->timezone)->startOfDay();
    }

    private function currentWeekStart(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone)->startOfWeek(CarbonImmutable::MONDAY);
    }
}
