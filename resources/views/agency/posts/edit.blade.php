@extends('layouts.agency')

@section('content')
    <form method="POST" action="{{ route('agency.posts.update', $post) }}"
          class="max-w-3xl space-y-5 rounded-xl border border-slate-200 bg-white p-6">
        @csrf
        @method('PUT')

        <div>
            <p class="text-sm text-slate-600">Editing a post for</p>
            <p class="font-medium">{{ $post->customer->name }}</p>
            {{--
             | The brand is shown, not chosen. Moving a post between clients
             | would carry its approval history, its comments and its targets
             | across the one boundary this application treats as absolute.
            --}}
        </div>

        @if ($post->status === \App\Domain\Publishing\Enums\PostStatus::Rejected)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                This post was rejected. Saving your changes returns it to draft so it can go
                back for approval.
            </div>
        @endif

        <div>
            <label for="title" class="block text-sm font-medium">Internal title</label>
            <x-agency.form.input id="title" name="title" value="{{ old('title', $post->title) }}" />
            <p class="mt-1 text-xs text-slate-600">Only your team sees this.</p>
        </div>

        <div>
            <label for="body" class="block text-sm font-medium">Content</label>
            <x-agency.form.textarea id="body" name="body" rows="6" required>{{ old('body', $post->body) }}</x-agency.form.textarea>
        </div>

        <fieldset>
            <legend class="text-sm font-medium">Attach media</legend>

            @if ($media->isEmpty())
                <p class="mt-2 text-sm text-slate-600">
                    Nothing in this brand's library yet.
                    <a href="{{ route('agency.media.index', ['brand' => $post->customer_id]) }}"
                       class="underline">Add some</a> and they will appear here.
                </p>
            @else
                <div class="mt-2 max-h-64 space-y-2 overflow-y-auto rounded-lg border border-slate-200 p-3">
                    @foreach ($media as $file)
                        <label class="flex items-start gap-2 text-sm">
                            <input type="checkbox" name="media[]" value="{{ $file->getKey() }}"
                                   @checked(in_array($file->getKey(), (array) old('media', $attachedMedia), false))
                                   class="mt-1 rounded border-slate-300">
                            <span class="min-w-0">
                                <span class="block truncate">{{ $file->original_name }}</span>
                                @if ($file->needsAltText())
                                    <a href="{{ route('agency.media.index', ['brand' => $file->customer_id]) }}"
                                       class="text-xs text-amber-700 underline">No description — add one</a>
                                @else
                                    <span class="block truncate text-xs text-slate-500">{{ $file->alt_text }}</span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>
            @endif
        </fieldset>

        <fieldset>
            <legend class="text-sm font-medium">Publish to</legend>

            @if ($accounts->isEmpty())
                <p class="mt-2 text-sm text-slate-600">
                    No connected accounts for this brand. You can still save the draft.
                </p>
            @else
                <div class="mt-2 space-y-2">
                    @foreach ($accounts as $account)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="accounts[]" value="{{ $account->getKey() }}"
                                   @checked(in_array($account->getKey(), (array) old('accounts', $attachedAccounts), false))
                                   class="rounded border-slate-300">
                            <span>{{ $account->name }}</span>
                            <span class="text-slate-500">({{ $account->provider_key }})</span>
                        </label>
                    @endforeach
                </div>
            @endif
        </fieldset>

        <div>
            <label for="scheduled_at" class="block text-sm font-medium">Schedule for</label>
            <input id="scheduled_at" name="scheduled_at" type="datetime-local"
                   value="{{ old('scheduled_at', $post->scheduled_at?->setTimezone($post->timezone)->format('Y-m-d\TH:i')) }}"
                   class="mt-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
            {{-- The post's own timezone, snapshotted when it was written, so a
                 brand that later moves zones does not silently shift this. --}}
            <p class="mt-1 text-xs text-slate-600">
                Times are in {{ $post->timezone }}. Setting one here does not schedule the
                post — it still has to be sent for approval or scheduled.
            </p>
        </div>

        <div class="flex items-center gap-3 pt-2">
            <x-agency.button>Save changes</x-agency.button>
            <a href="{{ route('agency.posts.show', $post) }}"
               class="text-sm text-slate-600 hover:underline">Cancel</a>
        </div>
    </form>
@endsection
