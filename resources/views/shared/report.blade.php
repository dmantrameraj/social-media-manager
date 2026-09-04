@php
    /** @var \App\Domain\Platform\Services\BrandingResolver $branding */
    $branding = app(\App\Domain\Platform\Services\BrandingResolver::class);

    // Unmeasured prints as a dash, never 0. In a client report those mean
    // opposite things.
    $show = fn (?int $v): string => $v === null ? '—' : number_format($v);
@endphp
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{--
     | Not indexed. The link is unguessable, but a search engine that finds one
     | in a referrer or a pasted email would publish a client's performance.
    --}}
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $brand?->name }} · {{ $branding->appName() }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="h-full bg-slate-50 text-slate-900 antialiased">
<div class="mx-auto max-w-5xl px-4 py-10 sm:px-6">

    {{-- The agency's brand, not the platform's: white labelling reaches here too. --}}
    <header class="mb-8 flex items-center gap-3">
        <span class="grid h-10 w-10 place-items-center rounded-lg text-sm font-semibold text-white"
              style="background-color: {{ $branding->primaryColor() }}">
            {{ $branding->initials() }}
        </span>
        <div>
            <p class="text-lg font-semibold">{{ $brand?->name }}</p>
            <p class="text-sm text-slate-600">
                {{ $from->toFormattedDateString() }} – {{ $to->toFormattedDateString() }}
            </p>
        </div>
    </header>

    @if ($totals['posts'] === 0)
        <p class="rounded-xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center text-sm text-slate-600">
            Nothing was published in this period.
        </p>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                'posts' => 'Posts',
                'impressions' => 'Impressions',
                'reach' => 'Reach',
                'engagements' => 'Engagements',
            ] as $key => $label)
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-semibold">{{ $show($totals[$key]) }}</p>
                </div>
            @endforeach
        </div>

        <section class="mt-8">
            <h2 class="mb-3 text-sm font-semibold">Posts</h2>
            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">Post</th>
                            <th scope="col" class="px-4 py-3 font-medium">Account</th>
                            <th scope="col" class="px-4 py-3 font-medium">Impressions</th>
                            <th scope="col" class="px-4 py-3 font-medium">Engagements</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="px-4 py-3">{{ $row['post'] }}</td>
                                <td class="px-4 py-3 text-slate-600">{{ $row['account'] }}</td>
                                <td class="px-4 py-3">{{ $show($row['impressions']) }}</td>
                                <td class="px-4 py-3">{{ number_format((int) $row['engagements']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <footer class="mt-10 text-xs text-slate-500">
        <p>
            {{-- Said plainly, because a stale link is confusing rather than alarming. --}}
            This link stops working on {{ $share->expires_at->toFormattedDateString() }}.
        </p>
        <p class="mt-1">Prepared by {{ $branding->appName() }}.</p>
    </footer>
</div>
</body>
</html>
