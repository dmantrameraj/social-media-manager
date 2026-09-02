@php
    /** @var \App\Domain\Platform\Services\BrandingResolver $branding */
    $branding = app(\App\Domain\Platform\Services\BrandingResolver::class);

    $user = auth('customer')->user();
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ $branding->appName() }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{--
 | A separate layout namespace from the agency surface, with no shared partial.
 | Sharing one would mean a nav item added for agency staff could appear here by
 | omission -- and every link a client should not see is a link they will click.
--}}
<body class="h-full bg-slate-50 text-slate-900 antialiased">

<div class="min-h-full">

    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-4xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
            <a href="{{ route('portal.dashboard') }}" class="flex items-center gap-3">
                <span class="grid h-9 w-9 place-items-center rounded-lg text-sm font-semibold text-white"
                      style="background-color: {{ $branding->primaryColor() }}">
                    {{ $branding->initials() }}
                </span>
                <span class="text-sm font-semibold">{{ $branding->appName() }}</span>
            </a>

            <nav class="flex items-center gap-4 text-sm" aria-label="Main">
                <a href="{{ route('portal.dashboard') }}"
                   @if (request()->routeIs('portal.dashboard')) aria-current="page" @endif
                   class="{{ request()->routeIs('portal.dashboard') ? 'font-medium text-slate-900' : 'text-slate-600 hover:text-slate-900' }}">
                    Overview
                </a>
                <a href="{{ route('portal.posts.index') }}"
                   @if (request()->routeIs('portal.posts.*')) aria-current="page" @endif
                   class="{{ request()->routeIs('portal.posts.*') ? 'font-medium text-slate-900' : 'text-slate-600 hover:text-slate-900' }}">
                    Content
                </a>

                <form method="POST" action="{{ route('portal.logout') }}">
                    @csrf
                    <button type="submit" class="text-slate-600 hover:text-slate-900">Sign out</button>
                </form>
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-4xl px-4 py-8 sm:px-6">
        <div class="mb-6">
            <h1 class="text-xl font-semibold">{{ $heading ?? ($title ?? 'Overview') }}</h1>
            @isset($subheading)
                <p class="mt-1 text-sm text-slate-600">{{ $subheading }}</p>
            @endisset
        </div>

        @if (session('status'))
            <div role="status"
                 class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                {{ session('status') }}
            </div>
        @endif

        @if (session('error'))
            <div role="alert"
                 class="mb-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                {{ session('error') }}
            </div>
        @endif

        @if ($errors->any())
            <div role="alert"
                 class="mb-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{ $slot ?? '' }}
        @yield('content')
    </main>

    <footer class="mx-auto max-w-4xl px-4 pb-10 text-xs text-slate-500 sm:px-6">
        Signed in as {{ $user?->name }}. Questions about your content go to your agency.
    </footer>
</div>

</body>
</html>
