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

    @if ($sessions->count() > 1)
        {{--
          | The action somebody actually wants when they think an account is
          | compromised: one click, everything else gone, without having to work
          | out which row is the laptop they left on a train.
        --}}
        <form method="POST" action="{{ route('agency.sessions.destroy-others') }}" class="mt-6">
            @csrf
            @method('DELETE')
            <button type="submit"
                    class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                Sign out every other device
            </button>
        </form>
    @endif

@endsection
