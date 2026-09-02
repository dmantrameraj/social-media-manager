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
