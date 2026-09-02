@extends('layouts.agency')

@section('content')
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['label' => 'Active brands', 'value' => $brandCount],
            ['label' => 'Drafts', 'value' => $draftCount],
            ['label' => 'Scheduled', 'value' => $scheduledCount],
            ['label' => 'Awaiting approval', 'value' => $needsApproval],
        ] as $stat)
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <p class="text-sm text-slate-600">{{ $stat['label'] }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $stat['value'] }}</p>
            </div>
        @endforeach
    </div>

    @if ($credits !== null)
        <div class="mt-4 rounded-xl border border-slate-200 bg-white p-5">
            <p class="text-sm text-slate-600">AI credits available</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($credits) }}</p>
        </div>
    @endif

    <h2 class="mt-8 mb-3 text-sm font-semibold text-slate-900">Next scheduled</h2>

    @if ($upcoming->isEmpty())
        @include('agency.partials.empty', [
            'title' => 'Nothing scheduled yet',
            'description' => 'Once you schedule a post it will appear here, with the soonest first.',
        ])
    @else
        <ul class="divide-y divide-slate-200 overflow-hidden rounded-xl border border-slate-200 bg-white">
            @foreach ($upcoming as $post)
                <li class="flex items-center justify-between gap-4 px-5 py-3">
                    <a href="{{ route('agency.posts.show', $post) }}"
                       class="min-w-0 text-sm font-medium hover:underline">
                        {{ $post->title ?: Str::limit($post->body, 60) }}
                    </a>
                    <time class="shrink-0 text-sm text-slate-600"
                          datetime="{{ $post->scheduled_at->toIso8601String() }}">
                        {{ $post->scheduled_at->timezone($post->timezone ?? 'UTC')->format('j M, H:i') }}
                    </time>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
