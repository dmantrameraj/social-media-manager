@extends('layouts.portal')

@section('content')

    <form method="GET" class="mb-5 flex flex-wrap items-end gap-3">
        @if ($brands->count() > 1)
            <label class="text-sm">
                <span class="block font-medium text-slate-700">Brand</span>
                <select name="brand" class="mt-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="">All</option>
                    @foreach ($brands as $brand)
                        <option value="{{ $brand->id }}" @selected($brandId === $brand->id)>{{ $brand->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        <label class="text-sm">
            <span class="block font-medium text-slate-700">Status</span>
            <select name="status" class="mt-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <option value="">Any</option>
                {{-- Only statuses a client may see: the list comes from
                     PortalPostQuery, so it cannot drift from the query. --}}
                @foreach ($statuses as $case)
                    <option value="{{ $case->value }}" @selected($status === $case)>{{ $case->label() }}</option>
                @endforeach
            </select>
        </label>

        <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white">Filter</button>
    </form>

    @if ($posts->isEmpty())
        <div class="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center">
            <p class="font-medium">No content here yet</p>
            <p class="mt-1 text-sm text-slate-600">
                Posts appear once your agency sends them for review.
            </p>
        </div>
    @else
        <ul class="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
            @foreach ($posts as $post)
                <li>
                    <a href="{{ route('portal.posts.show', $post) }}" class="block px-4 py-4 hover:bg-slate-50">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <span class="font-medium">{{ $post->title ?: Str::limit($post->body, 70) }}</span>
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium
                                {{ $post->status === \App\Domain\Publishing\Enums\PostStatus::ClientReview
                                    ? 'bg-amber-100 text-amber-900'
                                    : 'bg-slate-100 text-slate-700' }}">
                                {{ $post->status->label() }}
                            </span>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ $post->customer?->name }}
                            @if ($post->scheduled_at)
                                · {{ $post->scheduled_at->setTimezone($post->customer?->timezone ?? 'UTC')->format('j M Y, H:i') }}
                            @endif
                        </p>
                    </a>
                </li>
            @endforeach
        </ul>

        <div class="mt-4">{{ $posts->links() }}</div>
    @endif

@endsection
