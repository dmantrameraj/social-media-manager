@extends('layouts.auth')
@section('content')
<h2>Reset your password</h2>
<form method="POST" action="{{ route('password.email') }}">
    @csrf
    <label>Email <input type="email" name="email" value="{{ old('email') }}" required autofocus></label>
    <button type="submit">Email reset link</button>
</form>
@endsection
