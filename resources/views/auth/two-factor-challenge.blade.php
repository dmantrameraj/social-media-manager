@extends('layouts.auth')
@section('content')
<h2>Two-factor authentication</h2>
<form method="POST" action="{{ route('two-factor.login.store') }}">
    @csrf
    <label>Authentication code <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus></label>
    <label>Or a recovery code <input type="text" name="recovery_code" autocomplete="one-time-code"></label>
    <button type="submit">Verify</button>
</form>
@endsection
