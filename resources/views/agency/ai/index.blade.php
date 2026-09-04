@extends('layouts.agency')

@section('content')
    {{--
     | The AI studio. Twelve features, the credit ledger and the whole provider
     | abstraction were built and tested with no screen reaching any of them --
     | an agency's monthly credits could only be spent by a test.
    --}}

    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h2 class="text-sm font-medium text-slate-900">What would you like to make?</h2>
            <p class="mt-1 text-sm text-slate-600">
                Everything here is written against the brand you choose, using its
                Brand Brain. A thin profile gives thin results.
            </p>
        </div>

        {{-- Shown before anything is spent, not after. --}}
        <div class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm">
            <span class="text-slate-600">Credits available</span>
            <span class="ml-2 font-medium text-slate-900">{{ $account->available() }}</span>
            @if ($account->reserved > 0)
                <span class="ml-2 text-xs text-slate-500">({{ $account->reserved }} reserved)</span>
            @endif
        </div>
    </div>

    @if ($brands->isEmpty())
        @include('agency.partials.empty', [
            'title' => 'No brands yet',
            'description' => 'AI writes against a brand profile, so create one first.',
        ])
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($features as $feature)
                <a href="{{ route('agency.ai.show', $feature->key()) }}"
                   class="block rounded-xl border border-slate-200 bg-white p-4 hover:border-slate-300 hover:bg-slate-50">
                    <div class="flex items-start justify-between gap-3">
                        <span class="text-sm font-medium text-slate-900">{{ $feature->label() }}</span>
                        {{--
                         | The price is on the card, before the click. Finding out
                         | what something costs only after spending it is how a
                         | credit system loses trust.
                        --}}
                        <span class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
                            {{ ($costs[$feature->key()] ?? 1).' '.\Illuminate\Support\Str::plural('credit', $costs[$feature->key()] ?? 1) }}
                        </span>
                    </div>
                    <p class="mt-1 text-sm text-slate-600">{{ $feature->description() }}</p>
                </a>
            @endforeach
        </div>
    @endif
@endsection
