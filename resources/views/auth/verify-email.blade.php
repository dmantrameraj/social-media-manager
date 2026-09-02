@extends('layouts.auth')
@section('title', 'Verify your email')
@section('content')
<h2>Verify your email</h2>
<p>We sent a verification link to your inbox. Please click it to activate your account.</p>
<form method="POST" action="{{ route('verification.send') }}">
    @csrf
    <button type="submit">Resend verification email</button>
</form>
<form method="POST" action="{{ route('logout') }}">
    @csrf
    <button type="submit">Sign out</button>
</form>
@endsection
