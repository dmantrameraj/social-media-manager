@extends('layouts.agency')

@php
    /*
     | A metric that was never reported prints as a dash, not a zero. In a
     | client report those mean opposite things: one says "this was not
     | measured", the other says "this failed".
     */
    $show = fn (?int $value): string => $value === null ? '—' : number_format($value);
@endphp

@section('content')
    @if ($brands->isEmpty())
        @include('agency.partials.empty', [
            'title' => 'No brands yet',
            'description' => 'Analytics are reported per brand, so create one first.',
        ])
    @else
        <form method="GET" class="mb-6 flex flex-wrap items-end gap-3">
            <div>
                <label for="brand" class="block text-sm font-medium">Brand</label>
                <select id="brand" name="brand"
                        class="mt-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="">All brands</option>
                    @foreach ($brands as $brand)
                        <option value="{{ $brand->getKey() }}" @selected($selectedBrand === $brand->getKey())>
                            {{ $brand->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="days" class="block text-sm font-medium">Period</label>
                <select id="days" name="days"
                        class="mt-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @foreach ([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last year'] as $value => $label)
                        <option value="{{ $value }}" @selected($days === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <button type="submit"
                    class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50">
                Show
            </button>

            <p class="text-sm text-slate-500">
                {{ $from->toFormattedDateString() }} – {{ $to->toFormattedDateString() }}
            </p>
        </form>

        {{--
         | Export and sharing need a single brand: a spreadsheet or a link
         | covering "all brands" would put one client's figures in front of
         | another, which is the one thing a client report must never do.
        --}}
        @if ($selectedBrand !== null)
            <div class="mb-6 flex flex-wrap items-end gap-3">
                <a href="{{ route('agency.reports.export', ['brand' => $selectedBrand, 'days' => $days]) }}"
                   class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50">
                    Download CSV
                </a>

                <form method="POST" action="{{ route('agency.reports.share') }}"
                      class="flex flex-wrap items-end gap-2">
                    @csrf
                    <input type="hidden" name="brand" value="{{ $selectedBrand }}">
                    <input type="hidden" name="days" value="{{ $days }}">

                    <div>
                        <label for="expires_in_days" class="block text-xs font-medium text-slate-600">
                            Link expires in
                        </label>
                        <select id="expires_in_days" name="expires_in_days"
                                class="mt-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            @foreach ([7 => '7 days', 14 => '14 days', 30 => '30 days', 90 => '90 days'] as $value => $label)
                                <option value="{{ $value }}" @selected($value === 14)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <button type="submit"
                            class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50">
                        Create share link
                    </button>
                </form>
            </div>

            @if (session('share_url'))
                {{--
                 | Shown once. The plaintext token is never stored, so this is
                 | the only moment it exists -- and the message says so rather
                 | than letting somebody assume they can come back for it.
                --}}
                <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                    <p class="text-sm font-medium text-emerald-900">
                        Copy this link now. It is not shown again.
                    </p>
                    <p class="mt-2 break-all rounded-lg bg-white px-3 py-2 font-mono text-xs">
                        {{ session('share_url') }}
                    </p>
                </div>
            @endif
        @else
            <p class="mb-6 text-sm text-slate-500">
                Choose a single brand to export or share a report.
            </p>
        @endif

        @if ($totals['posts'] === 0)
            @include('agency.partials.empty', [
                'title' => 'Nothing collected yet',
                'description' => 'Figures appear once posts have published and been polled. Collection runs on a schedule.',
            ])
        @else
            {{--
             | Headline numbers. Each is the LATEST collection per post, not the
             | sum of every poll -- a post polled six times would otherwise be
             | counted six times, which is how an analytics screen produces
             | figures nobody can reconcile.
            --}}
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    'posts' => 'Posts',
                    'impressions' => 'Impressions',
                    'reach' => 'Reach',
                    'engagements' => 'Engagements',
                ] as $key => $label)
                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                        <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</p>
                        <p class="mt-1 text-2xl font-semibold text-slate-900">
                            {{ $show($totals[$key]) }}
                        </p>
                    </div>
                @endforeach
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                @foreach (['likes' => 'Likes', 'comments' => 'Comments', 'shares' => 'Shares', 'saves' => 'Saves', 'clicks' => 'Clicks'] as $key => $label)
                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                        <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</p>
                        <p class="mt-1 text-lg font-semibold text-slate-900">{{ $show($totals[$key]) }}</p>
                    </div>
                @endforeach
            </div>

            @if ($byAccount->isNotEmpty())
                <section class="mt-8">
                    <h2 class="mb-3 text-sm font-semibold">By account</h2>
                    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-slate-600">
                                <tr>
                                    <th scope="col" class="px-4 py-3 font-medium">Account</th>
                                    <th scope="col" class="px-4 py-3 font-medium">Posts</th>
                                    <th scope="col" class="px-4 py-3 font-medium">Impressions</th>
                                    <th scope="col" class="px-4 py-3 font-medium">Engagements</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($byAccount as $row)
                                    <tr>
                                        <td class="px-4 py-3">
                                            {{ $row['account']?->name ?? 'Removed account' }}
                                            <span class="block text-xs text-slate-500">
                                                {{ config("social.providers.{$row['account']?->provider_key}.name", $row['account']?->provider_key) }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3">{{ number_format($row['posts']) }}</td>
                                        <td class="px-4 py-3">{{ $show($row['totals']['impressions']) }}</td>
                                        <td class="px-4 py-3">{{ $show($row['totals']['engagements']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif

            <section class="mt-8">
                {{--
                 | Ranked by engagements, not impressions: an agency reporting
                 | to a client is asked what worked, and reach without
                 | interaction answers a different question.
                --}}
                <h2 class="mb-3 text-sm font-semibold">Best performing</h2>
                <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-slate-600">
                            <tr>
                                <th scope="col" class="px-4 py-3 font-medium">Post</th>
                                <th scope="col" class="px-4 py-3 font-medium">Impressions</th>
                                <th scope="col" class="px-4 py-3 font-medium">Engagements</th>
                                <th scope="col" class="px-4 py-3 font-medium">Rate</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($top as $metric)
                                <tr>
                                    <td class="px-4 py-3">
                                        @if ($metric->target?->post)
                                            <a href="{{ route('agency.posts.show', $metric->target->post) }}"
                                               class="font-medium text-slate-900 hover:underline">
                                                {{ $metric->target->post->title ?: 'Untitled post' }}
                                            </a>
                                        @else
                                            <span class="text-slate-500">Deleted post</span>
                                        @endif
                                        <span class="block text-xs text-slate-500">
                                            {{ $metric->customer?->name }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">{{ $show($metric->impressions) }}</td>
                                    <td class="px-4 py-3">{{ number_format($metric->engagements()) }}</td>
                                    <td class="px-4 py-3">
                                        {{-- Null, not 0%: unmeasured is not the same as nobody engaged. --}}
                                        @if ($metric->engagementRate() === null)
                                            <span class="text-slate-400">—</span>
                                        @else
                                            {{ number_format($metric->engagementRate() * 100, 1) }}%
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    @endif
@endsection
