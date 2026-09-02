@extends('layouts.admin')

@section('content')

    <form method="GET" class="mb-4 flex flex-wrap items-end gap-3">
        <label class="text-sm">
            <span class="block font-medium text-slate-700">Search</span>
            <input type="search" name="q" value="{{ $search }}" placeholder="Name or slug"
                   class="mt-1 w-64 rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </label>

        <label class="text-sm">
            <span class="block font-medium text-slate-700">Status</span>
            <select name="status" class="mt-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <option value="">Any</option>
                @foreach ($statuses as $case)
                    <option value="{{ $case->value }}" @selected($status === $case->value)>{{ $case->label() }}</option>
                @endforeach
            </select>
        </label>

        <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white">Filter</button>

        <a href="{{ route('admin.tenants.create') }}"
           class="ml-auto rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium">
            New agency
        </a>
    </form>

    @if ($tenants->isEmpty())
        <div class="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center">
            <p class="font-medium">No agencies match</p>
            <p class="mt-1 text-sm text-slate-600">Try a different search or status.</p>
        </div>
    @else
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="border-b border-slate-200 text-left text-slate-600">
                    <tr>
                        <th class="px-4 py-3 font-medium">Agency</th>
                        <th class="px-4 py-3 font-medium">Owner</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium text-right">Brands</th>
                        <th class="px-4 py-3 font-medium">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($tenants as $tenant)
                        <tr>
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.tenants.show', $tenant) }}" class="font-medium underline">
                                    {{ $tenant->name }}
                                </a>
                                <span class="block text-xs text-slate-500">{{ $tenant->slug }}</span>
                            </td>
                            <td class="px-4 py-3 text-slate-600">
                                {{ $tenant->owner?->name ?? '—' }}
                                <span class="block text-xs text-slate-500">{{ $tenant->owner?->email }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-1 text-xs font-medium
                                    {{ $tenant->permitsProductAccess() ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-800' }}">
                                    {{ $tenant->status->label() }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">{{ $tenant->customers_count }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $tenant->created_at?->format('j M Y') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $tenants->links() }}</div>
    @endif

@endsection
