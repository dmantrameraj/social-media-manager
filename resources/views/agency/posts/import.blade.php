@extends('layouts.agency')

@section('content')
    <div class="mx-auto max-w-3xl">
        <h1 class="text-lg font-semibold">Import posts</h1>
        <p class="mt-1 text-sm text-slate-600">
            A month of content in one file. Everything lands as a <strong>draft</strong> —
            importing never puts a post past approval or into a queue.
        </p>

        @if ($report !== null && $report->fatal !== null)
            <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                {{ $report->fatal }}
            </div>
        @elseif ($report !== null)
            <div class="mt-4 rounded-lg border px-3 py-2 text-sm
                {{ $report->failed() === 0
                    ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                    : 'border-amber-200 bg-amber-50 text-amber-900' }}">
                {{ $report->summary() }}
            </div>

            {{--
              Every row, not a count. An import that says "37 of 40" leaves
              somebody diffing two spreadsheets to find the three.
            --}}
            @if ($report->failures() !== [])
                <div class="mt-4 overflow-x-auto rounded-xl border border-slate-200">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                            <tr>
                                <th class="px-3 py-2">Line</th>
                                <th class="px-3 py-2">Title</th>
                                <th class="px-3 py-2">Why it was skipped</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($report->failures() as $row)
                                <tr>
                                    <td class="px-3 py-2 font-mono text-xs">{{ $row->line }}</td>
                                    <td class="px-3 py-2">{{ $row->title ?: '—' }}</td>
                                    <td class="px-3 py-2 text-slate-700">{{ $row->message }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-xs text-slate-500">
                    Line numbers count the header, so they match what you see when you open
                    the file. Fix those rows and upload again — the posts that imported are
                    already saved and will not be duplicated by rows you removed.
                </p>
            @endif

            @if ($report->created() > 0)
                <a href="{{ route('agency.posts.index') }}"
                   class="mt-4 inline-block rounded-lg bg-slate-900 px-3 py-1.5 text-sm text-white">
                    View the drafts
                </a>
            @endif
        @endif

        <form method="POST" action="{{ route('agency.posts.import.store') }}"
              enctype="multipart/form-data"
              class="mt-6 rounded-xl border border-slate-200 bg-white p-4">
            @csrf

            <label for="file" class="block text-sm font-medium">CSV file</label>
            <input type="file" name="file" id="file" accept=".csv,text/csv"
                   class="mt-1 block w-full text-sm" required>

            @error('file')
                <p class="mt-1 text-sm text-rose-700">{{ $message }}</p>
            @enderror

            <button type="submit"
                    class="mt-4 rounded-lg bg-slate-900 px-3 py-1.5 text-sm text-white">
                Import
            </button>

            <a href="{{ route('agency.posts.import.template') }}"
               class="ml-2 text-sm text-indigo-700 hover:underline">Download a template</a>
        </form>

        <div class="mt-6 rounded-xl border border-slate-200 bg-white p-4 text-sm">
            <h2 class="font-medium">The columns</h2>

            <dl class="mt-2 space-y-2 text-slate-700">
                <div><dt class="inline font-mono text-xs">brand</dt>
                    <dd class="inline"> — required. The brand name exactly as it appears in
                        your workspace.</dd></div>
                <div><dt class="inline font-mono text-xs">body</dt>
                    <dd class="inline"> — required. The post text.</dd></div>
                <div><dt class="inline font-mono text-xs">title</dt>
                    <dd class="inline"> — optional, for your own reference.</dd></div>
                <div><dt class="inline font-mono text-xs">scheduled_at</dt>
                    <dd class="inline"> — optional, e.g. <span class="font-mono text-xs">2026-04-15 09:00</span>.
                        Read in the brand's timezone. The post still has to be scheduled by
                        someone.</dd></div>
                <div><dt class="inline font-mono text-xs">accounts</dt>
                    <dd class="inline"> — optional. Account names or handles, comma separated.
                        Leave blank to choose destinations later.</dd></div>
            </dl>

            <p class="mt-3 text-xs text-slate-500">
                Up to {{ number_format($maxRows) }} rows per file. Media cannot be imported —
                matching a filename to a library item is a guess, and a wrong guess posts the
                wrong picture. Add images to the drafts afterwards.
            </p>
        </div>
    </div>
@endsection
