@extends('layouts.admin')

@section('content')

    <div class="mb-4 rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-600">
        Plans are read-only here. Editing one changes what every tenant on it is entitled to,
        retroactively and with no invoice to reconcile against — so plan changes ship as
        migrations. To give a single agency a different limit, use an entitlement override on
        that agency, which is scoped and audited.
    </div>

    @if ($plans->isEmpty())
        <div class="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center">
            <p class="font-medium">No plans</p>
            <p class="mt-1 text-sm text-slate-600">Seed the plan catalogue to see it here.</p>
        </div>
    @else
        <div class="grid gap-4 lg:grid-cols-2">
            @foreach ($plans as $plan)
                <section class="rounded-xl border border-slate-200 bg-white p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="font-semibold">{{ $plan->name }}</h2>
                            <p class="text-xs text-slate-500">{{ $plan->slug }}</p>
                        </div>
                        <span class="shrink-0 text-sm text-slate-600">
                            {{ number_format((int) ($subscriberCounts[$plan->id] ?? 0)) }} subscribers
                        </span>
                    </div>

                    <div class="mt-3 flex flex-wrap gap-2 text-xs">
                        <span class="rounded px-2 py-1 {{ $plan->is_active ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">
                            {{ $plan->is_active ? 'active' : 'inactive' }}
                        </span>
                        <span class="rounded px-2 py-1 {{ $plan->is_public ? 'bg-slate-100 text-slate-700' : 'bg-amber-50 text-amber-800' }}">
                            {{ $plan->is_public ? 'public' : 'private' }}
                        </span>
                        <span class="rounded bg-slate-100 px-2 py-1 text-slate-700">
                            {{ $plan->trial_days }} day trial
                        </span>
                    </div>

                    @php $planPrices = $prices[$plan->id] ?? collect(); @endphp
                    @if ($planPrices->isNotEmpty())
                        <ul class="mt-3 space-y-1 text-sm">
                            @foreach ($planPrices as $price)
                                <li class="flex justify-between">
                                    <span class="text-slate-500">{{ $price->interval ?? 'price' }}</span>
                                    <span class="font-medium">
                                        {{ strtoupper($price->currency ?? '') }}
                                        {{ number_format(((int) ($price->amount_minor ?? 0)) / 100, 2) }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @php $planFeatures = $features[$plan->id] ?? collect(); @endphp
                    @if ($planFeatures->isNotEmpty())
                        <details class="mt-3">
                            <summary class="cursor-pointer text-xs text-slate-500">
                                {{ $planFeatures->count() }} limits
                            </summary>
                            <table class="mt-2 w-full text-xs">
                                <tbody class="divide-y divide-slate-100">
                                    @foreach ($planFeatures as $feature)
                                        <tr>
                                            <td class="py-1 font-mono">{{ $feature->key }}</td>
                                            <td class="py-1 text-right">
                                                {{ $feature->value_type === 'unlimited' ? 'unlimited' : $feature->value }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </details>
                    @endif
                </section>
            @endforeach
        </div>
    @endif

@endsection
