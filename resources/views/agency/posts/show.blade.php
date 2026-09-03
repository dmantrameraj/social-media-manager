@extends('layouts.agency')

@section('content')
    <div class="grid gap-6 lg:grid-cols-3">

        <div class="lg:col-span-2 space-y-6">
            <article class="rounded-xl border border-slate-200 bg-white p-6">
                <p class="whitespace-pre-wrap text-sm">{{ $post->body }}</p>

                @if ($post->media->isNotEmpty())
                    {{--
                     | Previewed through agency.media.file: a signed, short-lived
                     | route that streams the bytes after MediaPolicy@download.
                     | Never a disk path -- the files are on a private disk.
                     |
                     | Images only. A video or PDF thumbnail would mean streaming
                     | the whole file to draw a 64px tile, so those show their
                     | type instead.
                    --}}
                    <div class="mt-5 border-t border-slate-100 pt-4">
                        <h2 class="text-sm font-semibold">
                            Attached media ({{ $post->media->count() }})
                        </h2>

                        <ol class="mt-2 space-y-3 text-sm">
                            @foreach ($post->media as $item)
                                @php $preview = $previews[$item->getKey()] ?? null; @endphp

                                <li class="flex items-start gap-3">
                                    @if ($preview !== null)
                                        {{-- Staff see what the client will see, rather than
                                             approving a filename. Signed and short-lived;
                                             the files are on a private disk. --}}
                                        <img src="{{ $preview }}" alt="{{ $item->describedAs() }}"
                                             loading="lazy"
                                             class="h-16 w-16 shrink-0 rounded-lg border border-slate-200 object-cover">
                                    @else
                                        <span class="grid h-16 w-16 shrink-0 place-items-center rounded-lg
                                                     border border-slate-200 bg-slate-50 text-xs text-slate-500">
                                            {{ strtoupper($item->extension) }}
                                        </span>
                                    @endif

                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate">{{ $item->original_name }}</span>
                                        @if ($item->needsAltText())
                                            {{-- Flagged where someone is already looking at
                                                 the post, while it can still be fixed before
                                                 it goes out. --}}
                                            <a href="{{ route('agency.media.index', ['brand' => $item->customer_id]) }}"
                                               class="text-xs text-amber-700 underline">
                                                No description — add one before publishing
                                            </a>
                                        @else
                                            <span class="block truncate text-xs text-slate-500">
                                                {{ $item->alt_text }}
                                            </span>
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ol>
                    </div>
                @endif
            </article>

            <section class="rounded-xl border border-slate-200 bg-white p-6">
                <h2 class="text-sm font-semibold">Destinations</h2>

                @if ($post->targets->isEmpty())
                    <p class="mt-2 text-sm text-slate-600">No destinations selected.</p>
                @else
                    {{--
                      Each destination shows its OWN status. A post to five networks
                      is five independent publications: one failing must never read
                      as the whole post having failed.
                    --}}
                    <ul class="mt-3 divide-y divide-slate-200">
                        @foreach ($post->targets as $target)
                            <li class="flex items-center justify-between gap-4 py-3 text-sm">
                                <div class="min-w-0">
                                    <p class="font-medium">{{ $target->socialAccount?->name ?? 'Account removed' }}</p>
                                    <p class="text-slate-600">{{ $target->provider_key }}</p>
                                </div>
                                <div class="shrink-0 text-right">
                                    <p>{{ ucfirst(str_replace('_', ' ', $target->status->value)) }}</p>
                                    @if ($target->last_error_message)
                                        {{-- Plain-language cause; raw provider detail stays
                                             behind posts.retry. --}}
                                        <p class="text-xs text-rose-700">{{ $target->last_error_message }}</p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            @if ($post->approvals->isNotEmpty())
                <section class="rounded-xl border border-slate-200 bg-white p-6">
                    <h2 class="text-sm font-semibold">History</h2>
                    <ol class="mt-3 space-y-2 text-sm">
                        @foreach ($post->approvals->sortByDesc('created_at') as $entry)
                            <li class="flex justify-between gap-4">
                                <span>
                                    {{ ucfirst($entry->action) }}
                                    <span class="text-slate-600">
                                        ({{ $entry->from_status }} → {{ $entry->to_status }})
                                    </span>
                                    @if ($entry->comment)
                                        <span class="block text-slate-600">“{{ $entry->comment }}”</span>
                                    @endif
                                </span>
                                <time class="shrink-0 text-slate-500"
                                      datetime="{{ $entry->created_at?->toIso8601String() }}">
                                    {{ $entry->created_at?->diffForHumans() }}
                                </time>
                            </li>
                        @endforeach
                    </ol>
                </section>
            @endif
        </div>

        <aside class="space-y-4">
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-sm font-semibold">Status</h2>
                <p class="mt-1 text-lg font-medium">{{ $post->status->label() }}</p>

                @if ($post->scheduled_at)
                    <p class="mt-1 text-sm text-slate-600">
                        Scheduled for
                        {{ $post->scheduled_at->timezone($post->timezone ?? 'UTC')->format('j M Y, H:i') }}
                        ({{ $post->timezone }})
                    </p>
                @endif

                @if ($post->source === 'ai')
                    <p class="mt-2 text-xs text-indigo-700">Drafted by AI. Review before approving.</p>
                @endif
            </div>

            @if ($allowedTransitions !== [])
                <div class="rounded-xl border border-slate-200 bg-white p-5">
                    <h2 class="text-sm font-semibold">Move to</h2>
                    {{--
                      Only transitions the state machine actually permits are
                      offered. The machine re-checks legality AND permission on
                      submit, so this list is a convenience, not the control.
                    --}}
                    <form method="POST" action="{{ route('agency.posts.transition', $post) }}" class="mt-3 space-y-3">
                        @csrf
                        <div>
                            <label for="status" class="sr-only">New status</label>
                            <select id="status" name="status"
                                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                @foreach ($allowedTransitions as $status)
                                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="comment" class="block text-sm font-medium">Comment</label>
                            <textarea id="comment" name="comment" rows="3"
                                      class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                        </div>
                        <button type="submit"
                                class="w-full rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                            Update status
                        </button>
                    </form>
                </div>
            @endif
        </aside>
    </div>
@endsection
