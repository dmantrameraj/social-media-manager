@extends('layouts.agency')

@section('content')

    <p class="mb-4 max-w-2xl text-sm text-slate-600">
        Every browser currently signed in to your account. If you do not recognise
        one, sign it out and change your password.
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
                        <td class="px-5 py-3 text-slate-600 tabular-nums">
                            {{ $session['ip_address'] ?? '--' }}
                        </td>
                        <td class="px-5 py-3 text-slate-600">
                            {{ \Illuminate\Support\Carbon::createFromTimestamp($session['last_active'])->diffForHumans() }}
                        </td>
                        <td class="px-5 py-3 text-right">
                            {{-- No control on the current row: signing yourself out
                                 from here would look like a crash. Log out does that. --}}
                            @unless ($session['is_current'])
                                <form method="POST" action="{{ route('agency.sessions.destroy', $session['id']) }}">
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

    <h2 class="mt-10 text-sm font-semibold">Recent account activity</h2>
    <p class="mb-4 mt-1 max-w-2xl text-sm text-slate-600">
        Sign-ins, failed attempts and password changes on this account. If something
        here was not you, change your password and sign out every other device.
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
                                {{--
                                  Flagged, not filtered. An ordinary sign-in is
                                  listed because "that was not me" is the
                                  observation only the account holder can make;
                                  flagging every one would make the flag mean
                                  nothing, and the one that matters would be
                                  lost among them.
                                --}}
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

    @if ($sessions->count() > 1)
        {{--
          | The action somebody actually wants when they think an account is
          | compromised: one click, everything else gone, without having to work
          | out which row is the laptop they left on a train.
        --}}
        <form method="POST" action="{{ route('agency.sessions.destroy-others') }}" class="mt-6">
            @csrf
            @method('DELETE')
            <x-agency.button>
                Sign out every other device
            </x-agency.button>
        </form>
    @endif

@endsection
