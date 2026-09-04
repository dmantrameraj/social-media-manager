@extends('layouts.agency')

@section('content')
    <div class="mb-4">
        <a href="{{ route('agency.inbox.index') }}" class="text-sm text-slate-600 hover:underline">
            &larr; Back to inbox
        </a>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-sm font-medium text-slate-900">
                    {{ $thread->participant_name ?: 'Someone' }}
                    <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-normal text-slate-600">
                        {{ $thread->kind->label() }}
                    </span>
                </h2>
                <p class="mt-1 text-sm text-slate-600">
                    {{ $thread->customer?->name }}
                    &middot;
                    {{ $thread->socialAccount?->name ?? 'Removed account' }}
                    @if ($thread->target?->post)
                        &middot;
                        <a href="{{ route('agency.posts.show', $thread->target->post) }}"
                           class="hover:underline">
                            on {{ $thread->target->post->title ?: 'a post' }}
                        </a>
                    @endif
                </p>

                <ul class="mt-5 space-y-3">
                    @foreach ($messages as $message)
                        {{--
                         | Internal notes look different from what the customer
                         | can read. Somebody writing a candid remark has to be
                         | able to tell at a glance which kind of message they
                         | are looking at.
                        --}}
                        <li @class([
                            'rounded-lg border p-3 text-sm',
                            'border-amber-200 bg-amber-50' => $message->is_internal,
                            'border-slate-200 bg-slate-50' => ! $message->is_internal
                                && $message->direction->value === 'inbound',
                            'border-slate-200 bg-white' => ! $message->is_internal
                                && $message->direction->value === 'outbound',
                        ])>
                            <p class="text-xs font-medium text-slate-500">
                                {{ $message->authorLabel() }}
                                &middot; {{ $message->posted_at?->diffForHumans() }}

                                @if ($message->direction->value === 'outbound' && ! $message->is_internal)
                                    {{--
                                     | Delivery is stated, never assumed. A reply
                                     | the platform refused is kept and shown as
                                     | unsent, so somebody retries rather than
                                     | believing the customer was answered.
                                    --}}
                                    <span @class([
                                        'ml-1 rounded-full px-2 py-0.5',
                                        'bg-emerald-50 text-emerald-700' => $message->delivery_status->value === 'delivered',
                                        'bg-slate-100 text-slate-600' => $message->delivery_status->value === 'pending',
                                        'bg-red-50 text-red-700' => $message->delivery_status->value === 'failed',
                                    ])>
                                        {{ $message->delivery_status->label() }}
                                    </span>
                                @endif
                            </p>
                            <p class="mt-1 whitespace-pre-wrap">{{ $message->body }}</p>
                        </li>
                    @endforeach
                </ul>

                @if ($canReply)
                    <form method="POST" action="{{ route('agency.inbox.reply', $thread) }}" class="mt-5">
                        @csrf

                        <label for="body" class="block text-sm font-medium">Reply</label>
                        <textarea id="body" name="body" rows="4" required minlength="1" maxlength="5000"
                                  class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        >{{ old('body') }}</textarea>
                        @error('body')
                            <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                        @enderror

                        <div class="mt-3 flex flex-wrap items-center gap-4">
                            {{--
                             | Internal is the default here too. Wrong in the
                             | safe direction means a colleague misses a note;
                             | wrong the other way sends a private remark to a
                             | customer.
                            --}}
                            <label class="flex items-center gap-2 text-sm">
                                <input type="radio" name="visibility" value="internal"
                                       @checked(old('visibility', 'internal') === 'internal')>
                                Internal note
                            </label>

                            @if ($replyable)
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="radio" name="visibility" value="public"
                                           @checked(old('visibility') === 'public')>
                                    Send to them
                                </label>
                            @endif

                            <x-agency.button>Post</x-agency.button>
                        </div>

                        @unless ($replyable)
                            {{--
                             | Said before they write, not after they submit.
                             | Most platforms restrict messages to a window
                             | after the person last wrote, and discovering that
                             | on submit wastes a carefully written answer.
                            --}}
                            <p class="mt-2 text-xs text-amber-700">
                                This conversation cannot be replied to from here right now.
                                You can still leave an internal note.
                            </p>
                        @endunless
                    </form>
                @endif
            </div>
        </div>

        <aside class="space-y-4">
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-sm font-medium text-slate-900">Handling</h2>

                <form method="POST" action="{{ route('agency.inbox.update', $thread) }}" class="mt-3">
                    @csrf
                    @method('PUT')

                    <label for="thread_status" class="block text-sm font-medium">Status</label>
                    <select id="thread_status" name="status"
                            class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($statuses as $case)
                            <option value="{{ $case->value }}" @selected($thread->status === $case)>
                                {{ $case->label() }}
                            </option>
                        @endforeach
                    </select>

                    <label for="assigned_to_user_id" class="mt-4 block text-sm font-medium">
                        Assigned to
                    </label>
                    <select id="assigned_to_user_id" name="assigned_to_user_id"
                            class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="">Nobody</option>
                        @foreach ($assignable as $member)
                            <option value="{{ $member->getKey() }}"
                                    @selected($thread->assigned_to_user_id === $member->getKey())>
                                {{ $member->name }}
                            </option>
                        @endforeach
                    </select>

                    <div class="mt-4">
                        <x-agency.button>Save</x-agency.button>
                    </div>
                </form>
            </div>
        </aside>
    </div>
@endsection
