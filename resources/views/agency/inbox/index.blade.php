@extends('layouts.agency')

@section('content')
    {{--
     | One queue for every network. An agency answering four platforms in four
     | browser tabs misses the one nobody had open, which is the entire reason
     | this screen exists.
    --}}

    {{--
     | Replies that never reached the network.
     |
     | Each one was already marked as failed inside its own thread, and only
     | there -- so finding one meant opening the thread you had no reason to
     | open. An agency believing it answered a customer it did not answer is
     | worse than not having replied, because nobody is waiting.
    --}}
    @if ($unsentCount > 0 && ! $unsent)
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
            {{ trans_choice(
                '{1} One reply never reached the network.|[2,*] :count replies never reached the network.',
                $unsentCount,
                ['count' => $unsentCount],
            ) }}
            <a href="{{ route('agency.inbox.index', ['unsent' => 1]) }}" class="ml-1 underline">
                Show them
            </a>
        </div>
    @endif

    @if ($unsent)
        <div class="mb-4 flex items-center justify-between rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
            <span>Showing threads with a reply that never sent, whatever their status.</span>
            <a href="{{ route('agency.inbox.index') }}" class="underline">Back to the inbox</a>
        </div>
    @endif

    <form method="GET" class="mb-6 flex flex-wrap items-end gap-3">
        <div>
            <label for="status" class="block text-sm font-medium">Show</label>
            <select id="status" name="status"
                    class="mt-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                @foreach ($statuses as $case)
                    <option value="{{ $case->value }}" @selected($status === $case->value)>
                        {{ $case->label() }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="brand" class="block text-sm font-medium">Brand</label>
            <select id="brand" name="brand"
                    class="mt-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <option value="">All brands</option>
                @foreach ($brands as $brand)
                    <option value="{{ $brand->getKey() }}" @selected($selectedBrand === $brand->getKey())>
                        {{ $brand->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="mine" value="1" @checked($mine)
                   class="rounded border-slate-300">
            Assigned to me
        </label>

        <button type="submit"
                class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50">
            Show
        </button>
    </form>

    @if ($threads->isEmpty())
        @include('agency.partials.empty', [
            'title' => 'Nothing here',
            'description' => 'Conversations appear as they arrive. Syncing runs on a schedule.',
        ])
    @else
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <ul class="divide-y divide-slate-100">
                @foreach ($threads as $thread)
                    <li>
                        <a href="{{ route('agency.inbox.show', $thread) }}"
                           class="block px-4 py-3 hover:bg-slate-50">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-slate-900">
                                        {{ $thread->participant_name ?: 'Someone' }}
                                        <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-normal text-slate-600">
                                            {{ $thread->kind->label() }}
                                        </span>
                                    </p>
                                    <p class="mt-0.5 truncate text-sm text-slate-600">
                                        {{ $thread->customer?->name }}
                                        &middot;
                                        {{ $thread->socialAccount?->name ?? 'Removed account' }}
                                    </p>
                                </div>

                                <div class="shrink-0 text-right">
                                    <p class="text-xs text-slate-500">
                                        {{ $thread->last_message_at?->diffForHumans() }}
                                    </p>
                                    @if ($thread->assignee)
                                        <p class="mt-0.5 text-xs text-slate-500">
                                            {{ $thread->assignee->name }}
                                        </p>
                                    @else
                                        {{--
                                         | Unassigned is worth saying out loud.
                                         | A queue where everything looks handled
                                         | is how a customer waits three days.
                                        --}}
                                        <p class="mt-0.5 text-xs font-medium text-amber-700">
                                            Unassigned
                                        </p>
                                    @endif
                                </div>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="mt-4">
            {{ $threads->links() }}
        </div>
    @endif
@endsection
