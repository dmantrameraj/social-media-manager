@extends('layouts.auth')
@section('content')
<h2>Confirm your password</h2>
<p>This is a sensitive area. Please confirm your password to continue.</p>
<form method="POST" action="{{ route('password.confirm.store') }}">
    @csrf
    <label>Password <input type="password" name="password" required autofocus></label>
    <button type="submit">Confirm</button>
</form>
@endsection
