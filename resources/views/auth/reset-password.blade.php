@extends('layouts.auth')
@section('content')
<h2>Choose a new password</h2>
<form method="POST" action="{{ route('password.update') }}">
    @csrf
    <input type="hidden" name="token" value="{{ $request->route('token') }}">
    <label>Email <input type="email" name="email" value="{{ old('email', $request->email) }}" required></label>
    <label>New password <input type="password" name="password" required autofocus></label>
    <label>Confirm password <input type="password" name="password_confirmation" required></label>
    <button type="submit">Reset password</button>
</form>
@endsection
