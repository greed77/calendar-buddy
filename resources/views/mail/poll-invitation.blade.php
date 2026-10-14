<x-mail::message>
# {{ $poll->title }}

{{ $organizer->name }} is scheduling a {{ $poll->duration_minutes }}-minute meeting and would like to know when you're available. There {{ $optionCount === 1 ? 'is 1 time' : "are $optionCount times" }} to choose from.

@if ($poll->location)
**Location:** {{ $poll->location }}
@endif

@if ($poll->description)
{{ $poll->description }}
@endif

<x-mail::button :url="$url">
Choose your times
</x-mail::button>

No account needed. This link is personal to you, so you can use it again later to change your answers.

Once a time is chosen, you'll get a calendar invitation.
</x-mail::message>
