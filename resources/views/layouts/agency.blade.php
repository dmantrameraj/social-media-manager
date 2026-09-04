@php
    /** @var \App\Domain\Platform\Services\BrandingResolver $branding */
    $branding = app(\App\Domain\Platform\Services\BrandingResolver::class);

    /*
     | Navigation is gated by PERMISSION, not by role: a tenant that edits its
     | own roles must not lose or gain menu items unexpectedly. A link the user
     | cannot use is never rendered -- offering an action that then 403s is
     | worse than not offering it.
     */
    $nav = collect([
        ['route' => 'agency.dashboard', 'label' => 'Dashboard', 'permission' => null],
        ['route' => 'agency.brands.index', 'label' => 'Brands', 'permission' => 'customers.view'],
        ['route' => 'agency.calendar', 'label' => 'Calendar', 'permission' => 'posts.view'],
        ['route' => 'agency.posts.create', 'label' => 'Create post', 'permission' => 'posts.create'],
        ['route' => 'agency.posts.import', 'label' => 'Import', 'permission' => 'posts.bulk_import'],
        ['route' => 'agency.media.index', 'label' => 'Media', 'permission' => 'media.view'],
        ['route' => 'agency.ai.index', 'label' => 'AI studio', 'permission' => 'ai.use'],
        ['route' => 'agency.inbox.index', 'label' => 'Inbox', 'permission' => 'inbox.view'],
        ['route' => 'agency.analytics.index', 'label' => 'Analytics', 'permission' => 'analytics.view'],
        ['route' => 'agency.social.index', 'label' => 'Accounts', 'permission' => 'social_accounts.view'],
        ['route' => 'agency.social.credentials', 'label' => 'Developer apps', 'permission' => 'social_credentials.manage'],
        ['route' => 'agency.team.index', 'label' => 'Team', 'permission' => 'team.view'],
        ['route' => 'agency.billing', 'label' => 'Billing', 'permission' => 'billing.view'],
        // Workspace-level and permission-gated, so it sits here rather than in
        // the account block below, which holds the signed-in person's own
        // preferences and gates on nothing but identity.
        ['route' => 'agency.settings.edit', 'label' => 'Settings', 'permission' => 'settings.view'],
        ['route' => 'agency.settings.branding', 'label' => 'Branding', 'permission' => 'settings.view'],
        ['route' => 'agency.settings.domains', 'label' => 'Domains', 'permission' => 'settings.view'],
    ])->filter(fn (array $item): bool => $item['permission'] === null
        || auth()->user()?->can($item['permission']) === true);

    /*
     | Counted once per render, not per nav item. These are the viewer's own
     | notifications, so no permission gates the link -- the relation scopes
     | them to the signed-in identity, which is the only boundary there is.
     */
    $unreadNotifications = auth()->user()?->unreadNotifications()->count() ?? 0;
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Read by any script that posts without a form; the calendar does. --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ $branding->appName() }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full bg-slate-50 text-slate-900 antialiased">

<div class="min-h-full lg:flex">

    {{-- Sidebar --}}
    <aside class="lg:w-64 lg:shrink-0 border-b lg:border-b-0 lg:border-r border-slate-200 bg-white">
        <div class="flex items-center gap-3 px-5 py-4 border-b border-slate-200">
            <span class="grid h-9 w-9 place-items-center rounded-lg text-sm font-semibold text-white"
                  style="background-color: {{ $branding->primaryColor() }}">
                {{ $branding->initials() }}
            </span>
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold">{{ $branding->appName() }}</p>
                @if ($branding->workspaceName())
                    <p class="truncate text-xs text-slate-500">{{ $branding->workspaceName() }}</p>
                @endif
            </div>
        </div>

        <nav class="p-3" aria-label="Main">
            <ul class="space-y-1">
                @foreach ($nav as $item)
                    @php $active = request()->routeIs($item['route']); @endphp
                    <li>
                        <a href="{{ route($item['route']) }}"
                           @if ($active) aria-current="page" @endif
                           class="block rounded-lg px-3 py-2 text-sm transition
                                  {{ $active
                                      ? 'bg-slate-900 text-white font-medium'
                                      : 'text-slate-700 hover:bg-slate-100' }}">
                            {{ $item['label'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <div class="border-t border-slate-200 p-3">
            {{-- Above the sign-out block so it is reachable from every page,
                 with the count in the link text rather than colour alone. --}}
            <a href="{{ route('agency.notifications.index') }}"
               @if (request()->routeIs('agency.notifications.*')) aria-current="page" @endif
               class="mb-2 flex items-center justify-between rounded-lg px-3 py-2 text-sm transition
                      {{ request()->routeIs('agency.notifications.*')
                          ? 'bg-slate-900 text-white font-medium'
                          : 'text-slate-700 hover:bg-slate-100' }}">
                <span>Notifications</span>
                @if ($unreadNotifications > 0)
                    <span class="rounded-full bg-slate-900 px-2 py-0.5 text-xs font-medium text-white
                                 {{ request()->routeIs('agency.notifications.*') ? 'bg-white text-slate-900' : '' }}">
                        {{ $unreadNotifications > 99 ? '99+' : $unreadNotifications }}
                        <span class="sr-only">unread</span>
                    </span>
                @endif
            </a>

            {{-- Account settings sit with the identity they belong to, next to
                 sign-out, rather than in the main nav: they are about the person
                 signed in, not about the agency's work. No permission gates
                 either -- they are the viewer's own. --}}
            <a href="{{ route('agency.notifications.settings') }}"
               @if (request()->routeIs('agency.notifications.settings*')) aria-current="page" @endif
               class="mb-1 block rounded-lg px-3 py-2 text-sm transition
                      {{ request()->routeIs('agency.notifications.settings*')
                          ? 'bg-slate-900 text-white font-medium'
                          : 'text-slate-700 hover:bg-slate-100' }}">
                Notification settings
            </a>

            <a href="{{ route('agency.sessions.index') }}"
               @if (request()->routeIs('agency.sessions.*')) aria-current="page" @endif
               class="mb-2 block rounded-lg px-3 py-2 text-sm transition
                      {{ request()->routeIs('agency.sessions.*')
                          ? 'bg-slate-900 text-white font-medium'
                          : 'text-slate-700 hover:bg-slate-100' }}">
                Signed-in devices
            </a>

            <p class="px-3 pb-2 text-xs text-slate-500 truncate">{{ auth()->user()?->name }}</p>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                        class="w-full rounded-lg px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-100">
                    Sign out
                </button>
            </form>
        </div>
    </aside>

    {{-- Main --}}
    <div class="flex-1 min-w-0">
        {{-- Above the tenant banner: knowing whose account you are in
             outranks knowing that account's billing state. --}}
        @include('partials.impersonation-banner')
        @include('agency.partials.tenant-banner')

        <header class="border-b border-slate-200 bg-white px-6 py-5">
            <h1 class="text-xl font-semibold">{{ $heading ?? ($title ?? 'Dashboard') }}</h1>
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

@livewireScripts
</body>
</html>
