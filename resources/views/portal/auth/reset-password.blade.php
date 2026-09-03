@extends('layouts.auth')
@section('title', 'Choose a new password')

@section('content')

<h2>Choose a new password</h2>

<form method="POST" action="{{ route('portal.password.update') }}">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">

    {{-- The address the token was issued for. The broker checks the two
         together, so it travels with the form rather than being retyped. --}}
    <label>Email
        <input type="email" name="email" value="{{ old('email', $email) }}" required autocomplete="username">
    </label>

    <label>New password
        <input type="password" name="password" required autofocus autocomplete="new-password">
    </label>

    <label>Confirm new password
        <input type="password" name="password_confirmation" required autocomplete="new-password">
    </label>

    <button type="submit">Save and sign in</button>
</form>

@endsection
