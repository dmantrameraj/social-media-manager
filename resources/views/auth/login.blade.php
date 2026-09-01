@extends('layouts.auth')
@section('content')
<h2>Sign in</h2>
<form method="POST" action="{{ route('login.store') }}">
    @csrf
    <label>Email <input type="email" name="email" value="{{ old('email') }}" required autofocus></label>
    <label>Password <input type="password" name="password" required></label>
    <label><input type="checkbox" name="remember"> Remember me</label>
    <button type="submit">Sign in</button>
</form>
<a href="{{ route('password.request') }}">Forgot your password?</a>
<a href="{{ route('register') }}">Create an agency account</a>
@endsection
