@extends('layouts.portal')

@section('content')

    @if ($awaitingCount > 0)
        <section class="mb-8 rounded-xl border border-amber-200 bg-amber-50 p-5">
            <h2 class="font-semibold text-amber-900">
                {{ $awaitingCount }} {{ Str::plural('post', $awaitingCount) }} waiting for you
            </h2>
            <p class="mt-1 text-sm text-amber-900">
                Nothing goes out until you have looked at it.
            </p>

            <ul class="mt-4 space-y-2">
                @foreach ($awaiting as $post)
                    <li>
                        <a href="{{ route('portal.posts.show', $post) }}"
                           class="flex flex-wrap items-baseline justify-between gap-2 rounded-lg bg-white px-4 py-3 text-sm hover:bg-amber-100/40">
                            <span class="font-medium">{{ $post->title ?: Str::limit($post->body, 60) }}</span>
                            <span class="text-xs text-slate-500">
                                {{ $post->customer?->name }}
                                @if ($post->scheduled_at)
                                    · for {{ $post->scheduled_at->format('j M') }}
                                @endif
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @else
        <section class="mb-8 rounded-xl border border-slate-200 bg-white p-8 text-center">
            <p class="font-medium">Nothing needs your attention</p>
            <p class="mt-1 text-sm text-slate-600">
                Your agency will send content here when it is ready for review.
            </p>
        </section>
    @endif

    <section class="mb-8">
        <h2 class="mb-3 text-sm font-semibold text-slate-700">Coming up</h2>

        @if ($upcoming->isEmpty())
            <div class="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-600">
                Nothing scheduled yet.
            </div>
        @else
            <ul class="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                @foreach ($upcoming as $post)
                    <li>
                        <a href="{{ route('portal.posts.show', $post) }}"
                           class="flex flex-wrap items-baseline justify-between gap-2 px-4 py-3 text-sm hover:bg-slate-50">
                            <span>{{ $post->title ?: Str::limit($post->body, 60) }}</span>
                            <span class="text-xs text-slate-500">
                                {{ $post->customer?->name }} ·
                                {{-- The brand's timezone, not the viewer's: "it went out
                                     an hour early" is the classic support ticket here. --}}
                                {{ $post->scheduled_at?->setTimezone($post->customer?->timezone ?? 'UTC')->format('j M, H:i') }}
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($brands->count() > 1)
        <section>
            <h2 class="mb-3 text-sm font-semibold text-slate-700">Your brands</h2>
            <ul class="flex flex-wrap gap-2">
                @foreach ($brands as $brand)
                    <li>
                        <a href="{{ route('portal.posts.index', ['brand' => $brand->id]) }}"
                           class="inline-block rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm hover:bg-slate-50">
                            {{ $brand->name }}
                            <span class="text-xs text-slate-500">
                                · {{ \App\Domain\Customers\Enums\PortalRole::from($brand->pivot->role)->label() }}
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

@endsection
