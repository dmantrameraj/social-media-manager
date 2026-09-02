@extends('layouts.agency')

@section('content')
    <div class="rounded-xl border border-slate-200 bg-white p-5">
        <p class="text-sm text-slate-600">Workspace status</p>
        <p class="mt-1 text-lg font-semibold">{{ $tenant->status->label() }}</p>
        @if ($tenant->trial_ends_at)
            <p class="mt-1 text-sm text-slate-600">
                Trial ends {{ $tenant->trial_ends_at->toFormattedDateString() }}.
            </p>
        @endif
    </div>

    <h2 class="mt-8 mb-3 text-sm font-semibold">Plan usage</h2>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="w-full text-sm">
            <thead class="border-b border-slate-200 text-left text-slate-600">
                <tr>
                    <th scope="col" class="px-5 py-3 font-medium">Limit</th>
                    <th scope="col" class="px-5 py-3 font-medium">Used</th>
                    <th scope="col" class="px-5 py-3 font-medium">Allowance</th>
                    <th scope="col" class="px-5 py-3 font-medium">Source</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                @foreach ($usage as $row)
                    <tr>
                        <td class="px-5 py-3">{{ $row['label'] }}</td>
                        <td class="px-5 py-3 tabular-nums">{{ $row['used'] }}</td>
                        <td class="px-5 py-3 tabular-nums">{{ $row['limit'] ?? 'Unlimited' }}</td>
                        {{-- Showing provenance turns "why can they do that?" into a one-glance answer. --}}
                        <td class="px-5 py-3 text-slate-600">{{ $row['source'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($credits)
        <p class="mt-4 text-sm text-slate-600">
            AI credits: {{ number_format($credits->available()) }} available
            of {{ number_format($credits->balance) }}.
        </p>
    @endif
@endsection
