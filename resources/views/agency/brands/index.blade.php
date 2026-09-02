@extends('layouts.agency')

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-slate-600">
            {{ $used }} of {{ $limit->isUnlimited() ? 'unlimited' : $limit->limit() }} brands used.
        </p>

        @if ($canCreate)
            <a href="{{ route('agency.brands.create') }}"
               class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                New brand
            </a>
        @elseif (! $limit->isUnlimited() && $used >= $limit->limit())
            {{-- The limit is stated with its remedy, rather than the button silently vanishing. --}}
            <p class="text-sm text-amber-800">
                You have reached your plan's brand limit.
                @can('billing.view')
                    <a href="{{ route('agency.billing') }}" class="underline">Review your plan</a>
                @endcan
            </p>
        @endif
    </div>

    @if ($brands->isEmpty())
        @include('agency.partials.empty', [
            'title' => 'No brands yet',
            'description' => 'A brand is a client workspace: its own content, media, social accounts and approvals.',
        ])
    @else
        <ul class="divide-y divide-slate-200 overflow-hidden rounded-xl border border-slate-200 bg-white">
            @foreach ($brands as $brand)
                <li class="flex items-center justify-between gap-4 px-5 py-4">
                    <div class="min-w-0">
                        <a href="{{ route('agency.brands.show', $brand) }}"
                           class="font-medium hover:underline">{{ $brand->name }}</a>
                        <p class="text-sm text-slate-600">
                            {{ $brand->industry ?: 'No industry set' }} · {{ $brand->timezone }}
                        </p>
                    </div>
                    @if ($brand->status !== \App\Domain\Customers\Enums\CustomerStatus::Active)
                        <span class="shrink-0 rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-700">
                            {{ $brand->status->label() }}
                        </span>
                    @endif
                </li>
            @endforeach
        </ul>

        <div class="mt-4">{{ $brands->links() }}</div>
    @endif
@endsection
