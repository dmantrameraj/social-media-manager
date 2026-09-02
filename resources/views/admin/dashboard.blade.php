@extends('layouts.admin')

@section('content')

    {{-- Health first. A business metric read off a platform that is silently
         not running is worse than no metric at all. --}}
    <section class="grid gap-4 sm:grid-cols-2">

        <div class="rounded-xl border p-5 {{ $scheduler['stale'] ? 'border-red-300 bg-red-50' : 'border-slate-200 bg-white' }}">
            <p class="text-sm font-medium {{ $scheduler['stale'] ? 'text-red-900' : 'text-slate-600' }}">Scheduler</p>

            @if ($scheduler['stale'])
                <p class="mt-1 text-lg font-semibold text-red-900">Not running</p>
                <p class="mt-1 text-sm text-red-800">
                    @if ($scheduler['last_beat'] === null)
                        No heartbeat has ever been recorded. Confirm the cron entry runs
                        <code class="rounded bg-red-100 px-1">schedule:run</code> with the correct PHP binary.
                    @else
                        Last beat {{ $scheduler['last_beat']->diffForHumans() }}. Publishing, credit resets and
                        the reservation sweeper are all stopped.
                    @endif
                </p>
            @else
                <p class="mt-1 text-lg font-semibold text-slate-900">Healthy</p>
                <p class="mt-1 text-sm text-slate-600">
                    Last beat {{ $scheduler['last_beat']->diffForHumans() }}.
                </p>
            @endif
        </div>

        <div class="rounded-xl border p-5 {{ $queue['warning'] || $queue['failed'] > 0 ? 'border-amber-300 bg-amber-50' : 'border-slate-200 bg-white' }}">
            <p class="text-sm font-medium text-slate-600">Queue</p>
            <p class="mt-1 text-lg font-semibold">{{ number_format($queue['pending']) }} pending</p>
            <p class="mt-1 text-sm text-slate-600">
                {{ number_format($queue['reserved']) }} in flight ·
                <a href="{{ route('admin.jobs.index') }}" class="underline">{{ number_format($queue['failed']) }} failed</a>
                @if ($queue['oldest_wait_seconds'] !== null)
                    · oldest waiting {{ round($queue['oldest_wait_seconds'] / 60) }} min
                @endif
            </p>
        </div>
    </section>

    <section class="mt-6">
        <h2 class="mb-3 text-sm font-semibold text-slate-700">Agencies</h2>
        <div class="grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <p class="text-xs text-slate-500">Total</p>
                <p class="mt-1 text-2xl font-semibold">{{ number_format($tenants['total']) }}</p>
            </div>
            @foreach (['trialing' => 'Trialing', 'active' => 'Active', 'grace' => 'Grace', 'suspended' => 'Suspended', 'cancelled' => 'Cancelled'] as $key => $label)
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-xs text-slate-500">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-semibold">{{ number_format($tenants[$key] ?? 0) }}</p>
                </div>
            @endforeach
        </div>
    </section>

    <section class="mt-6 grid gap-6 lg:grid-cols-2">

        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-slate-700">Subscriptions</h2>
            @if (count($subscriptions) === 0)
                <p class="mt-2 text-sm text-slate-500">No subscriptions yet.</p>
            @else
                <dl class="mt-3 space-y-1 text-sm">
                    @foreach ($subscriptions as $status => $count)
                        <div class="flex justify-between">
                            <dt class="text-slate-600">{{ ucfirst(str_replace('_', ' ', $status)) }}</dt>
                            <dd class="font-medium">{{ number_format($count) }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-slate-700">Open impersonation sessions</h2>
            @if ($openImpersonations->isEmpty())
                <p class="mt-2 text-sm text-slate-500">None. Nobody is inside a customer account.</p>
            @else
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach ($openImpersonations as $session)
                        <li class="flex justify-between gap-3">
                            <span class="truncate">{{ $session->superAdmin?->name ?? 'Unknown' }}</span>
                            <span class="shrink-0 text-slate-500">{{ $session->elapsedMinutes() }} min</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>

    <section class="mt-6 rounded-xl border border-slate-200 bg-white">
        <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
            <h2 class="text-sm font-semibold text-slate-700">Newest agencies</h2>
            <a href="{{ route('admin.tenants.index') }}" class="text-sm underline">All agencies</a>
        </div>

        @if ($recentTenants->isEmpty())
            <p class="p-5 text-sm text-slate-500">No agencies yet.</p>
        @else
            <ul class="divide-y divide-slate-100">
                @foreach ($recentTenants as $tenant)
                    <li class="flex items-center justify-between gap-4 px-5 py-3 text-sm">
                        <a href="{{ route('admin.tenants.show', $tenant) }}" class="truncate font-medium underline">
                            {{ $tenant->name }}
                        </a>
                        <span class="shrink-0 text-slate-500">
                            {{ $tenant->status->label() }} · {{ $tenant->created_at?->diffForHumans() }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

@endsection
