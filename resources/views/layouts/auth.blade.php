@php
    /** @var \App\Domain\Platform\Services\BrandingResolver $branding */
    $branding = app(\App\Domain\Platform\Services\BrandingResolver::class);
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Fortify renders these views itself, so it cannot pass a $title.
         A section is the only channel an @extends view has. --}}
    <title>@hasSection('title')@yield('title')@else{{ $title ?? 'Sign in' }}@endif - {{ $branding->appName() }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-100 text-slate-900 antialiased">

{{--
 | The auth views are deliberately class-free semantic markup -- label wrapping
 | its input, one form, no divs. Styling them individually would mean seven
 | copies of the same classes, so the shell styles its descendants instead
 | (see the `auth-shell` layer in resources/css/app.css). A new auth screen
 | inherits the design by existing.
--}}
<div class="flex min-h-full flex-col justify-center px-4 py-10 sm:px-6">
    <div class="mx-auto w-full max-w-md">

        <div class="mb-6 flex items-center justify-center gap-3">
            <span class="grid h-10 w-10 place-items-center rounded-lg text-sm font-semibold text-white"
                  style="background-color: {{ $branding->primaryColor() }}">
                {{ $branding->initials() }}
            </span>
            <span class="text-lg font-semibold">{{ $branding->appName() }}</span>
        </div>

        <main class="auth-shell rounded-xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            @if (session('status'))
                <p role="status"
                   class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                    {{ session('status') }}
                </p>
            @endif

            @if ($errors->any())
                <ul role="alert"
                    class="mb-5 space-y-1 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            @endif

            {{ $slot ?? '' }}
            @yield('content')
        </main>

        <p class="mt-6 text-center text-xs text-slate-500">
            &copy; {{ now()->year }} {{ $branding->appName() }}
        </p>
    </div>
</div>

</body>
</html>
