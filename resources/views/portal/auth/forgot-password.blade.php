@extends('layouts.auth')
@section('title', 'Reset your password')

@section('content')

<h2>Reset your password</h2>

<p class="mb-4">
    Enter the address your agency invited you with and we will send you a link.
</p>

<form method="POST" action="{{ route('portal.password.email') }}">
    @csrf
    <label>Email <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"></label>
    <button type="submit">Send the link</button>
</form>

<a href="{{ route('portal.login') }}">Back to sign in</a>

@endsection
