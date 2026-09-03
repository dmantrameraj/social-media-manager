@extends('layouts.agency')

@section('content')

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex gap-2 text-sm">
            <a href="{{ route('agency.notifications.index') }}"
               class="rounded-lg px-3 py-2 {{ $filter !== 'unread' ? 'bg-slate-900 text-white' : 'border border-slate-300 bg-white' }}">
                All
            </a>
            <a href="{{ route('agency.notifications.index', ['show' => 'unread']) }}"
               class="rounded-lg px-3 py-2 {{ $filter === 'unread' ? 'bg-slate-900 text-white' : 'border border-slate-300 bg-white' }}">
                Unread{{ $unreadCount > 0 ? ' ('.$unreadCount.')' : '' }}
            </a>
        </div>

        <div class="flex items-center gap-2">
            @if ($unreadCount > 0)
                <form method="POST" action="{{ route('agency.notifications.read-all') }}">
                    @csrf
                    <button type="submit" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm">
                        Mark all read
                    </button>
                </form>
            @endif

            {{-- Reachable from the list, which is where someone decides they are
                 getting too many of these. --}}
            <a href="{{ route('agency.notifications.settings') }}"
               class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm">
                Settings
            </a>
        </div>
    </div>

    @if ($notifications->isEmpty())
        @include('agency.partials.empty', [
            'title' => $filter === 'unread' ? 'Nothing unread' : 'No notifications yet',
            'description' => $filter === 'unread'
                ? 'You are up to date.'
                : 'Client decisions and publishing results appear here.',
        ])
    @else
        <ul class="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
            @foreach ($notifications as $notification)
                @php
                    /*
                     | Read from the stored payload, which is flat scalars
                     | snapshotted at dispatch. Deliberately NOT re-queried from
                     | the post: this row is read months later and must still
                     | render after the post it describes has been edited or
                     | deleted.
                     */
                    $data = (array) $notification->data;
                    $unread = $notification->read_at === null;
                @endphp

                <li class="{{ $unread ? 'bg-slate-50' : '' }}">
                    <form method="POST" action="{{ route('agency.notifications.read', $notification->id) }}">
                        @csrf
                        <button type="submit" class="flex w-full items-start gap-3 px-4 py-4 text-left hover:bg-slate-100">
                            {{-- The unread marker is a dot AND the row tint, so it does
                                 not rely on colour alone. --}}
                            <span aria-hidden="true"
                                  class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $unread ? 'bg-slate-900' : 'bg-transparent' }}"></span>

                            <span class="min-w-0 flex-1">
                                <span class="block text-sm {{ $unread ? 'font-medium' : '' }}">
                                    @if ($unread)
                                        <span class="sr-only">Unread. </span>
                                    @endif
                                    {{ $data['message'] ?? 'Notification' }}
                                </span>

                                @if (filled($data['comment'] ?? null))
                                    {{-- The client's own words. "They asked for changes"
                                         without the changes generates a second message
                                         asking what they were. --}}
                                    <span class="mt-1 block text-sm text-slate-600">
                                        &ldquo;{{ $data['comment'] }}&rdquo;
                                    </span>
                                @endif

                                <span class="mt-1 block text-xs text-slate-500">
                                    {{ $data['brand_name'] ?? '' }}
                                    · {{ $notification->created_at?->diffForHumans() }}
                                </span>
                            </span>
                        </button>
                    </form>
                </li>
            @endforeach
        </ul>

        <div class="mt-4">{{ $notifications->links() }}</div>
    @endif

@endsection
