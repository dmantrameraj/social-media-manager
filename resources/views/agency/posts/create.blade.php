@extends('layouts.agency')

@section('content')
    @if ($brands->isEmpty())
        @include('agency.partials.empty', [
            'title' => 'No brands yet',
            'description' => 'A post belongs to a brand, so create one first.',
        ])
    @else
        <form method="POST" action="{{ route('agency.posts.store') }}"
              class="max-w-3xl space-y-5 rounded-xl border border-slate-200 bg-white p-6">
            @csrf

            <div>
                <label for="customer_id" class="block text-sm font-medium">Brand</label>
                <select id="customer_id" name="customer_id" required
                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @foreach ($brands as $brand)
                        <option value="{{ $brand->getKey() }}" @selected(old('customer_id') == $brand->getKey())>
                            {{ $brand->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="title" class="block text-sm font-medium">Internal title</label>
                <input id="title" name="title" value="{{ old('title') }}"
                       class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <p class="mt-1 text-xs text-slate-600">Only your team sees this.</p>
            </div>

            <div>
                <label for="body" class="block text-sm font-medium">Content</label>
                <textarea id="body" name="body" rows="6" required
                          class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">{{ old('body') }}</textarea>
            </div>

            <fieldset>
                <legend class="text-sm font-medium">Attach media</legend>

                @if ($media->isEmpty())
                    <p class="mt-2 text-sm text-slate-600">
                        No files uploaded yet.
                        <a href="{{ route('agency.media.index') }}" class="underline">Add some to the library</a>
                        and they will appear here.
                    </p>
                @else
                    {{--
                     | Grouped by brand so switching the brand above never offers
                     | another client's library. Only files with status "ready"
                     | reach this list: attaching one that is still processing
                     | would fail at publish time, which is the worst moment to
                     | discover it.
                     |
                     | Selection order is not captured by checkboxes, so the
                     | order here is the library order. Drag-to-reorder belongs
                     | with the calendar work; until then the carousel sequence
                     | is the order shown.
                    --}}
                    <div class="mt-2 max-h-64 space-y-3 overflow-y-auto rounded-lg border border-slate-200 p-3">
                        @foreach ($media->groupBy('customer_id') as $brandId => $files)
                            <p class="text-xs font-medium text-slate-500">
                                {{ $brands->firstWhere('id', $brandId)?->name ?? 'Brand' }}
                            </p>

                            @foreach ($files as $file)
                                <label class="flex items-start gap-2 text-sm">
                                    <input type="checkbox" name="media[]" value="{{ $file->getKey() }}"
                                           @checked(in_array($file->getKey(), (array) old('media', []), false))
                                           class="mt-1 rounded border-slate-300">
                                    <span class="min-w-0">
                                        <span class="block truncate">{{ $file->original_name }}</span>
                                        @if ($file->needsAltText())
                                            {{-- Surfaced at the moment of use, where it can
                                                 still be fixed before the post goes out. --}}
                                            <a href="{{ route('agency.media.index', ['brand' => $file->customer_id]) }}"
                                               class="text-xs text-amber-700 underline">
                                                No description — add one
                                            </a>
                                        @else
                                            <span class="block truncate text-xs text-slate-500">
                                                {{ $file->alt_text }}
                                            </span>
                                        @endif
                                    </span>
                                </label>
                            @endforeach
                        @endforeach
                    </div>
                @endif
            </fieldset>

            <fieldset>
                <legend class="text-sm font-medium">Publish to</legend>
                @if ($accounts->isEmpty())
                    {{-- Only publishable accounts are ever offered: one behind an
                         expired connection would fail at publish time. --}}
                    <p class="mt-2 text-sm text-slate-600">
                        No connected accounts yet. You can still save a draft.
                    </p>
                @else
                    <div class="mt-2 space-y-2">
                        @foreach ($accounts as $account)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="accounts[]" value="{{ $account->getKey() }}"
                                       class="rounded border-slate-300">
                                <span>{{ $account->name }}</span>
                                <span class="text-slate-500">({{ $account->provider_key }})</span>
                            </label>
                        @endforeach
                    </div>
                @endif
            </fieldset>

            <div>
                <label for="scheduled_at" class="block text-sm font-medium">Schedule for</label>
                <input id="scheduled_at" name="scheduled_at" type="datetime-local"
                       value="{{ old('scheduled_at') }}"
                       class="mt-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                {{-- Entered in the brand's timezone and stored as UTC. Saying so
                     avoids the classic "it went out an hour early" support ticket. --}}
                <p class="mt-1 text-xs text-slate-600">
                    Interpreted in the brand's timezone. Leave blank to keep it as a draft.
                </p>
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit"
                        class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                    Save draft
                </button>
                <a href="{{ route('agency.posts.index') }}" class="text-sm text-slate-600 hover:underline">Cancel</a>
            </div>
        </form>
    @endif
@endsection
