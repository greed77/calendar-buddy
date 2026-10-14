<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased">
    <header class="border-b border-zinc-200 bg-white">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3">
            @auth
                <a href="{{ route('dashboard') }}" class="font-semibold">{{ config('app.name') }}</a>
            @else
                {{-- Outside invitees can't sign in, so don't link them to the login page. --}}
                <span class="font-semibold">{{ config('app.name') }}</span>
            @endauth

            @auth
                <div class="flex items-center gap-3 text-sm">
                    @if (auth()->user()->avatar_url)
                        <img src="{{ auth()->user()->avatar_url }}" alt="" class="h-7 w-7 rounded-full" referrerpolicy="no-referrer">
                    @endif
                    <span class="hidden sm:inline">{{ auth()->user()->name }}</span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="text-zinc-500 hover:text-zinc-900">Sign out</button>
                    </form>
                </div>
            @endauth
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-8">
        @foreach (['status' => 'border-emerald-200 bg-emerald-50 text-emerald-800', 'error' => 'border-red-200 bg-red-50 text-red-800'] as $key => $classes)
            @if (session($key))
                <div class="mb-6 rounded-md border px-4 py-3 text-sm {{ $classes }}">{{ session($key) }}</div>
            @endif
        @endforeach

        {{ $slot }}
    </main>
</body>
</html>
