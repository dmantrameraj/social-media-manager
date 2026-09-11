@extends('layouts.agency')

@section('content')
    <div class="mb-4">
        <h1 class="text-lg font-semibold">Activity log</h1>
        <p class="mt-1 max-w-2xl text-sm text-slate-600">
            What has been done in this workspace, by whom, and when. Content changes,
            approvals, connected accounts, team changes and billing adjustments.
        </p>
    </div>

    <form method="GET" class="mb-6 flex flex-wrap items-end gap-3">
        <div>
            <label for="action" class="block text-sm font-medium">Action</label>
            <select id="action" name="action"
                    class="mt-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <option value="">Everything</option>
                @foreach ($actions as $option)
                    <option value="{{ $option }}" @selected($filters['action'] === $option)>
                        {{ $option }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="actor" class="block text-sm font-medium">Person</label>
            <select id="actor" name="actor"
                    class="mt-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <option value="">Anyone</option>
                @foreach ($actors as $actor)
                    <option value="{{ $actor->id }}" @selected($filters['actor'] === $actor->id)>
                        {{ $actor->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <button type="submit" class="rounded-lg bg-slate-900 px-3 py-2 text-sm text-white">
            Filter
        </button>

        @if ($filters['action'] !== '' || $filters['actor'] !== null)
            <a href="{{ route('agency.audit') }}" class="text-sm text-slate-600 hover:underline">Clear</a>
        @endif
    </form>

    @if ($logs->isEmpty())
        @include('agency.partials.empty', [
            'title' => 'Nothing recorded yet',
            'description' => 'Actions taken in this workspace will appear here.',
        ])
    @else
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="w-full text-sm">
                <thead class="border-b border-slate-200 text-left text-slate-600">
                    <tr>
                        <th scope="col" class="px-5 py-3 font-medium">When</th>
                        <th scope="col" class="px-5 py-3 font-medium">Who</th>
                        <th scope="col" class="px-5 py-3 font-medium">Action</th>
                        <th scope="col" class="px-5 py-3 font-medium">What</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @foreach ($logs as $log)
                        <tr class="align-top">
                            <td class="px-5 py-3 text-slate-600">
                                <time datetime="{{ $log->created_at?->toIso8601String() }}"
                                      title="{{ $log->created_at?->toDayDateTimeString() }}">
                                    {{ $log->created_at?->diffForHumans() }}
                                </time>
                            </td>
                            <td class="px-5 py-3">
                                {{--
                                  A name where we have one. A system actor --
                                  the scheduler moving a post, a queue worker --
                                  has no name and says so rather than rendering
                                  a bare id nobody can interpret.
                                --}}
                                {{ $actorNames[$log->actor_id] ?? $log->actor_type->label() }}

                                @if ($log->impersonator_user_id !== null)
                                    {{--
                                      Surfaced deliberately. A platform
                                      administrator acting as one of your people
                                      is exactly the entry an agency has the most
                                      right to see, and hiding it would make this
                                      screen less honest than no screen at all.
                                    --}}
                                    <span class="ml-1 rounded bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900">
                                        via platform support
                                    </span>
                                @endif
                            </td>
                            <td class="px-5 py-3 font-mono text-xs">{{ $log->action }}</td>
                            <td class="px-5 py-3 text-slate-600">
                                @if ($log->auditable_type !== null)
                                    {{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}
                                @else
                                    —
                                @endif

                                @if ($log->old_values || $log->new_values)
                                    {{--
                                      Redacted on WRITE by SecretRedactor, so no
                                      secret can reach this column and therefore
                                      none can reach this page. §64 forbids a
                                      secret in an audit record at all, which is
                                      a stronger guarantee than hiding it here.
                                    --}}
                                    <details class="mt-1">
                                        <summary class="cursor-pointer text-xs text-slate-500">Details</summary>
                                        <pre class="mt-2 max-w-lg overflow-x-auto rounded bg-slate-50 p-2 text-xs">{{ json_encode(['old' => $log->old_values, 'new' => $log->new_values], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </details>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $logs->links() }}</div>
    @endif
@endsection
