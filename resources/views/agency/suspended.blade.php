@extends('layouts.agency')

@section('content')
    <div class="max-w-xl rounded-xl border border-amber-200 bg-amber-50 p-6">
        <h2 class="text-base font-semibold text-amber-900">This workspace is paused</h2>

        <p class="mt-2 text-sm text-amber-900">
            {{ $tenant->name }} is currently
            <strong>{{ strtolower($tenant->status->label()) }}</strong>, so content and
            publishing are unavailable. Nothing has been deleted.
        </p>

        @can('billing.view')
            <a href="{{ route('agency.billing') }}"
               class="mt-4 inline-block rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                Review billing
            </a>
        @else
            {{-- A member without billing rights cannot fix this themselves,
                 so tell them who can rather than offering a link that 403s. --}}
            <p class="mt-4 text-sm text-amber-900">
                Ask an account owner or administrator to review the subscription.
            </p>
        @endcan
    </div>
@endsection
