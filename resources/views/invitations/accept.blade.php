@extends('layouts.auth')

@section('content')
    <h2>Join workspace</h2>
    <p>You have been invited to join a workspace. Accepting will add your account to it.</p>

    <form method="POST" action="{{ route('invitations.store', ['token' => $token]) }}">
        @csrf
        <button type="submit">Accept invitation</button>
    </form>
@endsection
