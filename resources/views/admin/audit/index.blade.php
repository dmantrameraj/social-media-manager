@extends('layouts.admin')

@section('content')

    <form method="GET" class="mb-4 flex flex-wrap items-end gap-3">
        <label class="text-sm">
            <span class="block font-medium text-slate-700">Agency</span>
            <select name="tenant" class="mt-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <option value="">All</option>
                @foreach ($tenants as $tenant)
                    <option value="{{ $tenant->id }}" @selected($filters['tenant'] === $tenant->id)>{{ $tenant->name }}</option>
                @endforeach
            </select>
        </label>

        <label class="text-sm">
            <span class="block font-medium text-slate-700">Action</span>
            <select name="action" class="mt-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <option value="">All</option>
                @foreach ($actions as $action)
                    <option value="{{ $action }}" @selected($filters['action'] === $action)>{{ $action }}</option>
                @endforeach
            </select>
        </label>

        <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white">Filter</button>
    </form>

    @if ($logs->isEmpty())
        <div class="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center">
            <p class="font-medium">Nothing recorded</p>
            <p class="mt-1 text-sm text-slate-600">No audit entries match this filter.</p>
        </div>
    @else
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="border-b border-slate-200 text-left text-slate-600">
                    <tr>
                        <th class="px-4 py-3 font-medium">When</th>
                        <th class="px-4 py-3 font-medium">Action</th>
                        <th class="px-4 py-3 font-medium">Actor</th>
                        <th class="px-4 py-3 font-medium">Subject</th>
                        <th class="px-4 py-3 font-medium">Values</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($logs as $log)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3 text-slate-600">
                                {{ $log->created_at?->format('j M H:i') }}
                            </td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $log->action }}</td>
                            <td class="px-4 py-3 text-slate-600">
                                {{ $log->actor_type }} #{{ $log->actor_id ?? '—' }}
                                @if ($log->impersonator_user_id !== null)
                                    {{-- The reason both identities are stored: an action taken
                                         during support must never read as the customer's own. --}}
                                    <span class="block text-xs font-medium text-amber-700">
                                        via admin #{{ $log->impersonator_user_id }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-600">
                                @if ($log->auditable_type !== null)
                                    {{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                {{-- Already redacted on write by SecretRedactor, so this cannot
                                     print a token even if a future writer forgets. --}}
                                <details>
                                    <summary class="cursor-pointer text-xs text-slate-500">show</summary>
                                    <pre class="mt-2 max-w-md overflow-x-auto rounded bg-slate-50 p-2 text-xs">{{ json_encode(['old' => $log->old_values, 'new' => $log->new_values], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $logs->links() }}</div>
    @endif

@endsection
