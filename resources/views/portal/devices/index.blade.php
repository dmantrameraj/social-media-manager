@extends('layouts.portal')

@section('content')
    <div class="mb-6">
        <h1 class="text-xl font-semibold">Security</h1>
        <p class="mt-1 text-sm text-slate-600">
            Where your account is signed in, and what has happened to it recently.
        </p>
    </div>

    <section class="mb-8">
        <h2 class="mb-2 text-sm font-semibold text-slate-700">Signed-in devices</h2>
        <p class="mb-3 max-w-2xl text-sm text-slate-600">
            If you do not recognise one, sign it out and change your password.
        </p>

        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <table class="w-full text-sm">
                <thead class="border-b border-slate-200 text-left text-slate-600">
                    <tr>
                        <th scope="col" class="px-5 py-3 font-medium">Device</th>
                        <th scope="col" class="px-5 py-3 font-medium">IP address</th>
                        <th scope="col" class="px-5 py-3 font-medium">Last active</th>
                        <th scope="col" class="px-5 py-3 font-medium"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @foreach ($sessions as $session)
                        <tr>
                            <td class="px-5 py-3">
                                {{ $session['device'] }}
                                @if ($session['is_current'])
                                    <span class="ml-1 rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
                                        this device
                                    </span>
                                @endif
                            </td>
                            <td class="px-5 py-3 tabular-nums text-slate-600">
                                {{ $session['ip_address'] ?? '—' }}
                            </td>
                            <td class="px-5 py-3 text-slate-600">
                                {{ \Illuminate\Support\Carbon::createFromTimestamp($session['last_active'])->diffForHumans() }}
                            </td>
                            <td class="px-5 py-3 text-right">
                                {{-- No control on the current row: signing yourself
                                     out from here would look like a crash. --}}
                                @unless ($session['is_current'])
                                    <form method="POST"
                                          action="{{ route('portal.devices.destroy', $session['id']) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                                class="rounded-lg border border-slate-300 px-3 py-1 text-xs hover:bg-slate-50">
                                            Sign out
                                        </button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if (count($sessions) > 1)
            {{--
              What somebody actually wants when they think an account is
              compromised: one click, everything else gone, without working out
              which row is the laptop they left somewhere.
            --}}
            <form method="POST" action="{{ route('portal.devices.destroy-others') }}" class="mt-4">
                @csrf
                @method('DELETE')
                <button type="submit"
                        class="rounded-lg bg-slate-900 px-3 py-1.5 text-sm text-white">
                    Sign out every other device
                </button>
            </form>
        @endif
    </section>

    <section>
        <h2 class="mb-2 text-sm font-semibold text-slate-700">Recent account activity</h2>
        <p class="mb-3 max-w-2xl text-sm text-slate-600">
            Sign-ins, failed attempts and password changes. If something here was not you,
            change your password and sign out every other device.
        </p>

        @if ($activity->isEmpty())
            <p class="text-sm text-slate-600">Nothing recorded yet.</p>
        @else
            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 text-left text-slate-600">
                        <tr>
                            <th scope="col" class="px-5 py-3 font-medium">Event</th>
                            <th scope="col" class="px-5 py-3 font-medium">Where from</th>
                            <th scope="col" class="px-5 py-3 font-medium">IP address</th>
                            <th scope="col" class="px-5 py-3 font-medium">When</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @foreach ($activity as $entry)
                            <tr>
                                <td class="px-5 py-3">
                                    {{-- Flagged, not filtered. An ordinary sign-in is
                                         listed because "that was not me" is the
                                         observation only the account holder can make. --}}
                                    @if ($entry->event->isSecurityRelevant())
                                        <span class="rounded bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900">
                                            {{ $entry->event->label() }}
                                        </span>
                                    @else
                                        {{ $entry->event->label() }}
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-slate-600">
                                    {{ collect([$entry->browser, $entry->platform])->filter()->implode(' on ') ?: '—' }}
                                </td>
                                <td class="px-5 py-3 tabular-nums text-slate-600">{{ $entry->ip ?: '—' }}</td>
                                <td class="px-5 py-3 text-slate-600">
                                    <time datetime="{{ $entry->created_at->toIso8601String() }}">
                                        {{ $entry->created_at->diffForHumans() }}
                                    </time>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
