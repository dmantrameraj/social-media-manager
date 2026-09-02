@extends('layouts.agency')

@section('content')
    @if ($posts->isEmpty())
        @include('agency.partials.empty', [
            'title' => 'No content yet',
            'description' => 'Drafts, scheduled posts and published content all appear here.',
        ])
    @else
        <ul class="divide-y divide-slate-200 overflow-hidden rounded-xl border border-slate-200 bg-white">
            @foreach ($posts as $post)
                <li class="flex items-center justify-between gap-4 px-5 py-4">
                    <div class="min-w-0">
                        <a href="{{ route('agency.posts.show', $post) }}"
                           class="font-medium hover:underline">
                            {{ $post->title ?: Str::limit($post->body, 60) }}
                        </a>
                        <p class="text-sm text-slate-600">
                            {{ $post->status->label() }}
                            @if ($post->source === 'ai')
                                · <span class="text-indigo-700">AI drafted</span>
                            @endif
                        </p>
                    </div>
                    @if ($post->scheduled_at)
                        <time class="shrink-0 text-sm text-slate-600"
                              datetime="{{ $post->scheduled_at->toIso8601String() }}">
                            {{ $post->scheduled_at->timezone($post->timezone ?? 'UTC')->format('j M, H:i') }}
                        </time>
                    @endif
                </li>
            @endforeach
        </ul>

        <div class="mt-4">{{ $posts->links() }}</div>
    @endif
@endsection
