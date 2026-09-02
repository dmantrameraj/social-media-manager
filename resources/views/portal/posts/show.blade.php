@extends('layouts.portal')

@section('content')

    {{--
     | NOTE FOR ANYONE EXTENDING THIS PAGE.
     |
     | Nothing here may render internal comments, the post's version history,
     | which agency member wrote it, social account credentials, or any other
     | brand's content. The comment list is filtered in the QUERY by
     | clientVisible(); do not swap that for a filter in this template.
     | See docs/04-AUTH-RBAC.md section 8.
    --}}

    <a href="{{ route('portal.posts.index') }}" class="mb-4 inline-block text-sm text-slate-600 hover:text-slate-900">
        &larr; All content
    </a>

    <article class="rounded-xl border border-slate-200 bg-white p-6">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <p class="text-sm text-slate-500">{{ $post->customer?->name }}</p>
            <span class="rounded-full px-2 py-0.5 text-xs font-medium
                {{ $post->status === \App\Domain\Publishing\Enums\PostStatus::ClientReview
                    ? 'bg-amber-100 text-amber-900'
                    : 'bg-slate-100 text-slate-700' }}">
                {{ $post->status->label() }}
            </span>
        </div>

        @if ($post->title)
            <h2 class="mt-2 text-lg font-semibold">{{ $post->title }}</h2>
        @endif

        <div class="mt-4 whitespace-pre-wrap text-sm leading-relaxed">{{ $post->body }}</div>

        @if ($post->scheduled_at)
            <p class="mt-4 border-t border-slate-100 pt-4 text-sm text-slate-600">
                Planned for
                <strong>{{ $post->scheduled_at->setTimezone($post->customer?->timezone ?? 'UTC')->format('j M Y \a\t H:i') }}</strong>
                ({{ $post->customer?->timezone ?? 'UTC' }})
            </p>
        @endif

        @if ($post->targets->isNotEmpty())
            <p class="mt-2 text-sm text-slate-600">
                Going to:
                {{ $post->targets->map(fn ($t) => ucfirst($t->provider_key ?? 'account'))->unique()->implode(', ') }}
            </p>
        @endif
    </article>

    {{-- Decision --}}
    @if ($canDecide)
        <section class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-6">
            <h2 class="font-semibold text-amber-900">Your decision</h2>
            <p class="mt-1 text-sm text-amber-900">
                Nothing is published until you approve it.
            </p>

            <form method="POST" class="mt-4 space-y-3" id="decision">
                @csrf
                <label class="block text-sm">
                    <span class="font-medium text-amber-950">Notes for your agency (optional when approving)</span>
                    <textarea name="comment" rows="3" maxlength="2000"
                              class="mt-1 w-full rounded-lg border border-amber-300 px-3 py-2 text-sm"
                              placeholder="Anything you would like changed?"></textarea>
                </label>

                <div class="flex flex-wrap gap-2">
                    <button type="submit" formaction="{{ route('portal.posts.approve', $post) }}"
                            class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800">
                        Approve
                    </button>

                    {{-- The answer clients actually want most of the time, so it
                         sits beside approve rather than being buried. --}}
                    <button type="submit" formaction="{{ route('portal.posts.changes', $post) }}"
                            class="rounded-lg border border-amber-400 bg-white px-4 py-2 text-sm font-medium text-amber-900 hover:bg-amber-100">
                        Request changes
                    </button>

                    <button type="submit" formaction="{{ route('portal.posts.reject', $post) }}"
                            class="rounded-lg border border-rose-300 bg-white px-4 py-2 text-sm font-medium text-rose-800 hover:bg-rose-50">
                        Reject
                    </button>
                </div>
            </form>
        </section>
    @elseif ($isViewerOnly)
        {{-- Explain the absence, rather than letting it read as a broken page. --}}
        <p class="mt-6 rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600">
            You have view-only access to this brand. Someone else on your team approves content.
        </p>
    @endif

    {{-- Conversation --}}
    <section class="mt-6">
        <h2 class="mb-3 text-sm font-semibold text-slate-700">Conversation</h2>

        @if ($comments->isEmpty())
            <p class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-6 text-center text-sm text-slate-600">
                No comments yet.
            </p>
        @else
            <ul class="space-y-3">
                @foreach ($comments as $comment)
                    <li class="rounded-xl border border-slate-200 bg-white p-4">
                        <p class="text-xs font-medium text-slate-500">
                            {{ $comment->authorLabel() }} · {{ $comment->created_at?->diffForHumans() }}
                        </p>
                        <p class="mt-1 whitespace-pre-wrap text-sm">{{ $comment->body }}</p>
                    </li>
                @endforeach
            </ul>
        @endif

        <form method="POST" action="{{ route('portal.posts.comment', $post) }}" class="mt-4">
            @csrf
            <label class="block text-sm">
                <span class="font-medium text-slate-700">Add a comment</span>
                <textarea name="body" rows="3" required minlength="2" maxlength="2000"
                          class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
            </label>
            <button type="submit" class="mt-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white">
                Post comment
            </button>
        </form>
    </section>

@endsection
