@extends('layouts.auth')
@section('title', 'Client sign in')

@section('content')

<h2>Client sign in</h2>

<p class="mb-4">Review and approve the content your agency has prepared for you.</p>

<form method="POST" action="{{ route('portal.login.store') }}">
    @csrf
    <label>Email <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"></label>
    <label>Password <input type="password" name="password" required autocomplete="current-password"></label>
    <label><input type="checkbox" name="remember"> Remember me</label>
    <button type="submit">Sign in</button>
</form>

<a href="{{ route('portal.password.request') }}">Forgot your password?</a>

{{--
 | Deliberately no "create an account" link. Client logins are issued by the
 | agency through an invitation; there is no self-service path onto this
 | surface, and offering one would be a way in for anyone who guesses the URL.
--}}
<a href="{{ route('login') }}">Agency team member? Sign in here</a>

@endsection
