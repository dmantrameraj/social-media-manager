@extends('layouts.admin')

@section('content')

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex items-end gap-3">
            <label class="text-sm">
                <span class="block font-medium text-slate-700">Queue</span>
                <select name="queue" class="mt-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="">All</option>
                    @foreach ($queues as $name)
                        <option value="{{ $name }}" @selected($queue === $name)>{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <x-admin.button>Filter</x-admin.button>
        </form>

        <p class="text-sm text-slate-600">{{ number_format($pending) }} jobs pending</p>
    </div>

    @if ($jobs->isEmpty())
        <div class="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center">
            <p class="font-medium">No failed jobs</p>
            <p class="mt-1 text-sm text-slate-600">Nothing has failed on this queue.</p>
        </div>
    @else
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="border-b border-slate-200 text-left text-slate-600">
                    <tr>
                        <th class="px-4 py-3 font-medium">Failed</th>
                        <th class="px-4 py-3 font-medium">Job</th>
                        <th class="px-4 py-3 font-medium">Queue</th>
                        <th class="px-4 py-3 font-medium">Error</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($jobs as $job)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3 text-slate-600">
                                {{ \Illuminate\Support\Carbon::parse($job->failed_at)->format('j M H:i') }}
                            </td>
                            <td class="px-4 py-3 font-medium">{{ $job->job_name }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $job->queue }}</td>
                            <td class="px-4 py-3 text-xs text-slate-600">{{ $job->exception_summary }}</td>
                            <td class="px-4 py-3 text-right">
                                <form method="POST" action="{{ route('admin.jobs.retry', $job->uuid) }}">
                                    @csrf
                                    <button type="submit" class="text-xs underline">Retry</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="mt-3 text-xs text-slate-500">
            Retrying a publishing job can put a post on a customer's live account. Retries are audited.
        </p>

        <div class="mt-4">{{ $jobs->links() }}</div>
    @endif

@endsection
