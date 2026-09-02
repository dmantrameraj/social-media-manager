@extends('layouts.auth')
@section('title', 'Two-factor authentication')

@section('content')

<h2>Two-factor authentication</h2>

@if ($required)
    <p class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-900">
        Platform administration requires two-factor authentication. You cannot reach
        <code>/admin</code> until this is confirmed.
    </p>
@endif

@if ($state === 'disabled')
    <p class="mb-4">
        Add a second step to signing in. You will need an authenticator app such as
        1Password, Authy or Google Authenticator.
    </p>

    <form method="POST" action="{{ route('two-factor.enable') }}">
        @csrf
        <button type="submit">Begin setup</button>
    </form>

@elseif ($state === 'pending')
    <p class="mb-4">
        Scan this code with your authenticator app, then enter the six-digit code it shows.
        Two-factor is not active until you confirm it — that is deliberate, so a mistyped
        scan cannot lock you out at your next sign-in.
    </p>

    <div class="mb-4 flex justify-center rounded-lg border border-slate-200 bg-white p-4">
        {!! $qrCode !!}
    </div>

    <p class="mb-4 text-center text-xs break-all">
        Can't scan? Enter this key manually:
        <code class="font-mono">{{ $secret }}</code>
    </p>

    <form method="POST" action="{{ route('two-factor.confirm') }}">
        @csrf
        <label>
            Six-digit code
            <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
                   pattern="[0-9]*" required autofocus>
        </label>
        <button type="submit">Confirm and enable</button>
    </form>

    <form method="POST" action="{{ route('two-factor.disable') }}">
        @csrf
        @method('DELETE')
        <button type="submit" style="background:none;color:inherit;text-decoration:underline;width:auto">
            Cancel setup
        </button>
    </form>

@else
    <p class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-emerald-900">
        Two-factor authentication is active.
    </p>

    @if (! empty($recoveryCodes))
        <p class="mb-2">
            Store these recovery codes somewhere safe. Each one works once, and they are the
            only way back in if you lose your authenticator.
        </p>
        <ul class="mb-4 grid grid-cols-2 gap-1 rounded-lg border border-slate-200 bg-slate-50 p-3 font-mono text-xs">
            @foreach ($recoveryCodes as $code)
                <li>{{ $code }}</li>
            @endforeach
        </ul>
    @endif

    <p>
        <a href="{{ $required ? route('admin.dashboard') : route('agency.dashboard') }}">Continue</a>
    </p>
@endif

@endsection
