@php
    /** @var \App\Domain\Platform\Services\BrandingResolver $branding */
    $branding = app(\App\Domain\Platform\Services\BrandingResolver::class);

    /*
     | Gated by platform permission, exactly like the agency nav. Super Admins
     | hold all of these today, but the check is what lets a narrower staff role
     | ship later without revisiting every template.
     */
    $nav = collect([
        ['route' => 'admin.dashboard', 'label' => 'Overview', 'permission' => null],
        ['route' => 'admin.tenants.index', 'label' => 'Agencies', 'permission' => 'platform.tenants.manage'],
        ['route' => 'admin.plans.index', 'label' => 'Plans', 'permission' => 'platform.plans.manage'],
        ['route' => 'admin.jobs.index', 'label' => 'Failed jobs', 'permission' => 'platform.jobs.view'],
        ['route' => 'admin.audit.index', 'label' => 'Audit log', 'permission' => 'platform.audit.view'],
    ])->filter(fn (array $item): bool => $item['permission'] === null
        || auth()->user()?->can($item['permission']) === true);
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ isset($title) ? $title.' · ' : '' }}Platform · {{ $branding->appName() }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{--
 | Slate-900 chrome rather than the agency's white.
 |
 | Not decoration: staff move between the two surfaces all day, and the cost of
 | mistaking "every agency" for "one agency" is doing something destructive to
 | the wrong account. The chrome should never be ambiguous at a glance.
--}}
<body class="h-full bg-slate-100 text-slate-900 antialiased">

<div class="min-h-full lg:flex">

    <aside class="bg-slate-900 text-slate-200 lg:w-64 lg:shrink-0">
        <div class="flex items-center gap-3 border-b border-white/10 px-5 py-4">
            <span class="grid h-9 w-9 place-items-center rounded-lg bg-white/10 text-sm font-semibold">PA</span>
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-white">Platform admin</p>
                <p class="truncate text-xs text-slate-400">{{ $branding->appName() }}</p>
            </div>
        </div>

        <nav class="p-3" aria-label="Platform">
            <ul class="space-y-1">
                @foreach ($nav as $item)
                    @php $active = request()->routeIs($item['route']); @endphp
                    <li>
                        <a href="{{ route($item['route']) }}"
                           @if ($active) aria-current="page" @endif
                           class="block rounded-lg px-3 py-2 text-sm transition
                                  {{ $active ? 'bg-white text-slate-900 font-medium' : 'text-slate-300 hover:bg-white/10' }}">
                            {{ $item['label'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <div class="border-t border-white/10 p-3">
            <a href="{{ route('agency.dashboard') }}"
               class="block rounded-lg px-3 py-2 text-sm text-slate-300 hover:bg-white/10">
                Leave platform admin
            </a>
            <p class="px-3 pt-2 text-xs text-slate-400 truncate">{{ auth()->user()?->name }}</p>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="w-full rounded-lg px-3 py-2 text-left text-sm text-slate-300 hover:bg-white/10">
                    Sign out
                </button>
            </form>
        </div>
    </aside>

    <div class="flex-1 min-w-0">
        <header class="border-b border-slate-200 bg-white px-6 py-5">
            <h1 class="text-xl font-semibold">{{ $heading ?? ($title ?? 'Platform') }}</h1>
            @isset($subheading)
                <p class="mt-1 text-sm text-slate-600">{{ $subheading }}</p>
            @endisset
        </header>

        <main class="p-6">
            @include('agency.partials.flash')
            {{ $slot ?? '' }}
            @yield('content')
        </main>
    </div>
</div>

</body>
</html>
