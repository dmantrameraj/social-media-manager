@extends('layouts.agency')

@section('content')
    @php
        $start = $month->copy()->startOfMonth()->startOfWeek();
        $end = $month->copy()->endOfMonth()->endOfWeek();
        $canMove = auth()->user()->can('posts.schedule');
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

    @if ($canMove)
        <p class="mb-3 text-xs text-slate-500">
            Drag a post to another day to move it. Published posts, and posts being published
            right now, stay where they are.
        </p>
    @endif

    <div id="calendar-error"
         class="mb-3 hidden rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
         role="alert"></div>

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
                <div class="calendar-day min-h-24 bg-white p-2 {{ $inMonth ? '' : 'opacity-50' }}"
                     data-date="{{ $key }}">
                    <p class="text-xs {{ $day->isToday() ? 'font-bold text-indigo-700' : 'text-slate-500' }}">
                        {{ $day->day }}
                    </p>

                    {{--
                      Only a capped preview per day. A busy agency can have
                      hundreds of posts in a month, and rendering every one is a
                      guaranteed incident.
                    --}}
                    @foreach ($dayPosts->take(3) as $post)
                        @php $draggable = $canMove && in_array($post->getKey(), $movable, true); @endphp
                        <a href="{{ route('agency.posts.show', $post) }}"
                           @if ($draggable) draggable="true" data-post="{{ $post->getKey() }}" @else draggable="false" @endif
                           class="calendar-post mt-1 block truncate rounded bg-slate-100 px-1.5 py-1 text-xs hover:bg-slate-200 {{ $draggable ? 'cursor-move' : '' }}"
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

    @if ($canMove)
        <script>
            /*
             | Drag-and-drop rescheduling.
             |
             | This decides nothing. It sends a post id and a date; the server
             | checks permission, tenancy, brand access, post state, targets in
             | flight and lead time, and refuses with a message if any of that
             | fails. Everything here is presentation -- which is why a post
             | that is not draggable is merely not draggable, rather than being
             | the reason it cannot move.
             */
            (function () {
                const token = document.querySelector('meta[name="csrf-token"]')?.content
                    ?? document.querySelector('input[name="_token"]')?.value;
                const errorBox = document.getElementById('calendar-error');
                let dragging = null;

                const fail = (message) => {
                    errorBox.textContent = message;
                    errorBox.classList.remove('hidden');
                };

                document.querySelectorAll('.calendar-post[draggable="true"]').forEach((chip) => {
                    chip.addEventListener('dragstart', (event) => {
                        dragging = chip.dataset.post;
                        // Without this Firefox refuses to start the drag.
                        event.dataTransfer.setData('text/plain', dragging);
                        event.dataTransfer.effectAllowed = 'move';
                    });

                    chip.addEventListener('dragend', () => {
                        dragging = null;
                    });
                });

                document.querySelectorAll('.calendar-day').forEach((cell) => {
                    cell.addEventListener('dragover', (event) => {
                        if (dragging === null) {
                            return;
                        }
                        // Preventing the default is what marks the cell as a
                        // valid drop target; without it the drop never fires.
                        event.preventDefault();
                        event.dataTransfer.dropEffect = 'move';
                        cell.classList.add('bg-indigo-50');
                    });

                    cell.addEventListener('dragleave', () => cell.classList.remove('bg-indigo-50'));

                    cell.addEventListener('drop', async (event) => {
                        event.preventDefault();
                        cell.classList.remove('bg-indigo-50');

                        const post = dragging ?? event.dataTransfer.getData('text/plain');

                        if (!post) {
                            return;
                        }

                        errorBox.classList.add('hidden');

                        try {
                            // Built from the named route with a placeholder,
                            // so a change to the URL prefix cannot silently
                            // break this without breaking the route too.
                            const endpoint = @json(route('agency.posts.reschedule', ['post' => '__POST__']));

                            const response = await fetch(endpoint.replace('__POST__', post), {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json',
                                    'X-CSRF-TOKEN': token,
                                },
                                body: JSON.stringify({ date: cell.dataset.date }),
                            });

                            const payload = await response.json().catch(() => ({}));

                            if (!response.ok) {
                                fail(payload.message ?? 'That move was refused.');
                                return;
                            }

                            // Reload rather than move the chip by hand: the day
                            // a post lands on depends on its brand timezone,
                            // and the server has already worked that out.
                            window.location.reload();
                        } catch (e) {
                            fail('Could not reach the server. The post has not moved.');
                        }
                    });
                });
            })();
        </script>
    @endif
@endsection
