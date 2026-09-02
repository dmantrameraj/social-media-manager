@extends('layouts.admin')

@section('content')

    <form method="POST" action="{{ route('admin.tenants.store') }}"
          class="max-w-xl space-y-4 rounded-xl border border-slate-200 bg-white p-6">
        @csrf

        <label class="block text-sm">
            <span class="font-medium text-slate-700">Agency name</span>
            <input type="text" name="name" value="{{ old('name') }}" required minlength="2" maxlength="120"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </label>

        <label class="block text-sm">
            <span class="font-medium text-slate-700">Owner name</span>
            <input type="text" name="owner_name" value="{{ old('owner_name') }}" required minlength="2" maxlength="120"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </label>

        <label class="block text-sm">
            <span class="font-medium text-slate-700">Owner email</span>
            <input type="email" name="owner_email" value="{{ old('owner_email') }}" required maxlength="255"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <span class="mt-1 block text-xs text-slate-500">
                If this address already has an account it is reused, so one person can own more than one agency.
            </span>
        </label>

        <label class="block text-sm">
            <span class="font-medium text-slate-700">Reason</span>
            <input type="text" name="reason" value="{{ old('reason') }}" required minlength="5" maxlength="500"
                   placeholder="Signed contract, migrated from a competitor, internal test account…"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </label>

        {{--
         | There is no password field, and one must never be added. Staff do not
         | choose a customer's credential: the owner arrives through the normal
         | password-reset flow, so nobody at the platform ever holds it.
        --}}
        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">
            No password is set here. The owner receives one through the password-reset flow,
            so platform staff never hold a customer credential.
        </div>

        <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white">
            Create agency
        </button>
    </form>

@endsection
