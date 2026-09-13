@extends('layouts.agency')

@section('content')
    <div class="mx-auto max-w-5xl">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-lg font-semibold">Recurring posts</h1>
                <p class="mt-1 text-sm text-slate-600">
                    Standing rules that create posts on a cadence. A rule never publishes
                    anything itself — it creates ordinary posts
                    {{ config('publishing.recurrence_horizon_days', 60) }} days
                    ahead, and those go through the same approval and scheduling as the
                    rest of your content.
                </p>
            </div>

            @can('posts.create')
                <a href="{{ route('agency.posts.recurring.create') }}"
                   class="shrink-0 rounded-lg bg-slate-900 px-3 py-1.5 text-sm text-white">
                    New rule
                </a>
            @endcan
        </div>

        @if ($rules->isEmpty())
            @include('agency.partials.empty', [
                'title' => 'No recurring posts yet',
                'description' => 'A rule is useful for the things you post on a schedule anyway — a weekly tip, a monthly newsletter promo.',
            ])
        @else
            <div class="mt-6 overflow-x-auto rounded-xl border border-slate-200">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-3 py-2">Rule</th>
                            <th class="px-3 py-2">Brand</th>
                            <th class="px-3 py-2">Cadence</th>
                            <th class="px-3 py-2">Next</th>
                            <th class="px-3 py-2">Posts</th>
                            <th class="px-3 py-2">Status</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($rules as $rule)
                            <tr class="{{ $rule->is_active && ! $rule->hasEnded() ? '' : 'bg-slate-50/60' }}">
                                <td class="px-3 py-2">
                                    <span class="font-medium">{{ $rule->name }}</span>
                                    <p class="mt-0.5 max-w-xs truncate text-xs text-slate-500">
                                        {{ $rule->body }}
                                    </p>
                                </td>

                                <td class="px-3 py-2 text-slate-700">{{ $rule->customer?->name ?? '—' }}</td>

                                <td class="px-3 py-2 text-slate-700">
                                    {{ $rule->cadenceSummary() }}
                                    <span class="block text-xs text-slate-500">{{ $rule->timezone }}</span>
                                </td>

                                {{--
                                  The dates, not just the cadence. "Every 2 weeks on Tue,
                                  Thu" is a sentence people misread, and a list that only
                                  restates it back at them does not help anybody check
                                  they built the rule they meant to.
                                --}}
                                <td class="px-3 py-2 text-xs text-slate-700">
                                    @forelse ($upcoming[$rule->getKey()] ?? [] as $date)
                                        <span class="block">{{ $date }}</span>
                                    @empty
                                        <span class="text-slate-400">—</span>
                                    @endforelse
                                </td>

                                <td class="px-3 py-2 text-slate-700">{{ $rule->posts_count }}</td>

                                <td class="px-3 py-2">
                                    @if ($rule->hasEnded())
                                        {{-- Ended is not paused: resuming it would do nothing. --}}
                                        <span class="rounded-full bg-slate-200 px-2 py-0.5 text-xs text-slate-700">
                                            Ended
                                        </span>
                                    @elseif ($rule->is_active)
                                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800">
                                            Active
                                        </span>
                                    @else
                                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-900">
                                            Paused
                                        </span>
                                    @endif
                                </td>

                                <td class="px-3 py-2 text-right whitespace-nowrap">
                                    @can('posts.update')
                                        @unless ($rule->hasEnded())
                                            <form method="POST" class="inline"
                                                  action="{{ route('agency.posts.recurring.toggle', $rule) }}">
                                                @csrf
                                                <button type="submit" class="text-xs text-indigo-700 hover:underline">
                                                    {{ $rule->is_active ? 'Pause' : 'Resume' }}
                                                </button>
                                            </form>
                                        @endunless

                                        <form method="POST" class="ml-2 inline"
                                              action="{{ route('agency.posts.recurring.destroy', $rule) }}"
                                              onsubmit="return confirm('Delete this rule? The posts it already created are kept.')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-xs text-rose-700 hover:underline">
                                                Delete
                                            </button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="mt-3 text-xs text-slate-500">
                Pausing a rule stops it creating anything new. Posts it has already made stay
                where they are — including ones a client has approved.
            </p>
        @endif
    </div>
@endsection
