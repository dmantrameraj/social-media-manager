@extends('layouts.auth')
@section('title', 'Create your account')
@section('content')
<h2>Create your agency account</h2>
<form method="POST" action="{{ route('register.store') }}">
    @csrf
    <label>Your name <input type="text" name="name" value="{{ old('name') }}" required autofocus></label>
    <label>Agency name <input type="text" name="agency_name" value="{{ old('agency_name') }}" required></label>
    <label>Email <input type="email" name="email" value="{{ old('email') }}" required></label>
    <label>Password <input type="password" name="password" required></label>
    <label>Confirm password <input type="password" name="password_confirmation" required></label>
    <button type="submit">Start free trial</button>
</form>
<a href="{{ route('login') }}">Already have an account?</a>
@endsection
