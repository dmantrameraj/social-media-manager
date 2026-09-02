@extends('layouts.agency')

@section('content')
    @php
        $start = $month->copy()->startOfMonth()->startOfWeek();
        $end = $month->copy()->endOfMonth()->endOfWeek();
    @endphp

    <div class="mb-4 flex items-center justify-between">
        <div class="flex items-center gap-2">
            <a href="{{ route('agency.calendar', ['month' => $month->copy()->subMonth()->format('Y-m-01')]) }}"
               class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">Previous</a>
            <a href="{{ route('agency.calendar', ['month' => $month->copy()->addMonth()->format('Y-m-01')]) }}"
               class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">Next</a>
        </div>
        <p class="text-sm font-medium">{{ $month->format('F Y') }}</p>
    </div>

    <div class="overflow-x-auto">
        <div class="grid min-w-3xl grid-cols-7 gap-px rounded-xl border border-slate-200 bg-slate-200">
            @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $day)
                <div class="bg-slate-50 px-2 py-2 text-center text-xs font-medium text-slate-600">{{ $day }}</div>
            @endforeach

            @for ($day = $start->copy(); $day->lte($end); $day->addDay())
                @php
                    $key = $day->toDateString();
                    $dayPosts = $posts->get($key, collect());
                    $inMonth = $day->month === $month->month;
                @endphp
                <div class="min-h-24 bg-white p-2 {{ $inMonth ? '' : 'opacity-50' }}">
                    <p class="text-xs {{ $day->isToday() ? 'font-bold text-indigo-700' : 'text-slate-500' }}">
                        {{ $day->day }}
                    </p>

                    {{--
                      Only a capped preview per day. A busy agency can have
                      hundreds of posts in a month, and rendering every one is a
                      guaranteed incident.
                    --}}
                    @foreach ($dayPosts->take(3) as $post)
                        <a href="{{ route('agency.posts.show', $post) }}"
                           class="mt-1 block truncate rounded bg-slate-100 px-1.5 py-1 text-xs hover:bg-slate-200"
                           title="{{ $post->title ?: Str::limit($post->body, 80) }}">
                            {{ $post->title ?: Str::limit($post->body, 24) }}
                        </a>
                    @endforeach

                    @if ($dayPosts->count() > 3)
                        <p class="mt-1 text-xs text-slate-500">+{{ $dayPosts->count() - 3 }} more</p>
                    @endif
                </div>
            @endfor
        </div>
    </div>
@endsection
