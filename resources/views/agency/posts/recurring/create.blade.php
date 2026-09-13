@extends('layouts.agency')

@section('content')
    @if ($brands->isEmpty())
        @include('agency.partials.empty', [
            'title' => 'No brands yet',
            'description' => 'A recurring rule belongs to a brand, so create one first.',
        ])
    @else
        <form method="POST" action="{{ route('agency.posts.recurring.store') }}"
              class="max-w-3xl space-y-5 rounded-xl border border-slate-200 bg-white p-6">
            @csrf

            <div>
                <h1 class="text-lg font-semibold">New recurring post</h1>
                <p class="mt-1 text-sm text-slate-600">
                    This creates posts, it does not publish them. Each occurrence becomes an
                    ordinary post {{ $horizon }} days ahead and goes through the same
                    approval and scheduling as anything else — so if the brand requires
                    client approval, they will arrive as drafts for review.
                </p>
            </div>

            @if ($errors->any())
                <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                    Some details need fixing before this rule can be saved.
                </div>
            @endif

            <div>
                <label for="name" class="block text-sm font-medium">Rule name</label>
                <x-agency.form.input id="name" name="name" value="{{ old('name') }}" required
                                     placeholder="Weekly coffee tip" />
                <p class="mt-1 text-xs text-slate-600">Only your team sees this.</p>
                @error('name')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="customer_id" class="block text-sm font-medium">Brand</label>
                <x-agency.form.select id="customer_id" name="customer_id" required>
                    @foreach ($brands as $brand)
                        <option value="{{ $brand->getKey() }}" @selected(old('customer_id') == $brand->getKey())>
                            {{ $brand->name }} ({{ $brand->effectiveTimezone() }})
                        </option>
                    @endforeach
                </x-agency.form.select>
                <p class="mt-1 text-xs text-slate-600">
                    The time below is read in the brand's timezone, and stays fixed to it even
                    if the brand moves later.
                </p>
                @error('customer_id')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="title" class="block text-sm font-medium">Internal title</label>
                <x-agency.form.input id="title" name="title" value="{{ old('title') }}" />
                @error('title')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="body" class="block text-sm font-medium">Content</label>
                <x-agency.form.textarea id="body" name="body" rows="5" required>{{ old('body') }}</x-agency.form.textarea>
                <p class="mt-1 text-xs text-slate-600">
                    Every occurrence starts from this text. Edit an individual post afterwards
                    if one week needs to say something different.
                </p>
                @error('body')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror
            </div>

            <fieldset class="rounded-lg border border-slate-200 p-4">
                <legend class="px-1 text-sm font-medium">How often</legend>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="frequency" class="block text-sm font-medium">Repeats</label>
                        <x-agency.form.select id="frequency" name="frequency" required>
                            @foreach ($frequencies as $frequency)
                                <option value="{{ $frequency->value }}" @selected(old('frequency') === $frequency->value)>
                                    {{ $frequency->label() }}
                                </option>
                            @endforeach
                        </x-agency.form.select>
                    </div>

                    <div>
                        <label for="interval" class="block text-sm font-medium">Every</label>
                        <x-agency.form.input type="number" id="interval" name="interval" min="1" max="52"
                                             value="{{ old('interval', 1) }}" required />
                        <p class="mt-1 text-xs text-slate-600">
                            2 with “every week” means fortnightly.
                        </p>
                        @error('interval')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror
                    </div>
                </div>

                {{--
                  Both cadence fields are always shown rather than toggled by
                  script. The server stores only the ones the chosen frequency
                  uses and ignores the rest, so a page with JavaScript disabled
                  still builds a correct rule — and the labels say which applies
                  to what.
                --}}
                <div class="mt-4">
                    <span class="block text-sm font-medium">Days — for weekly rules</span>
                    <div class="mt-2 flex flex-wrap gap-3">
                        @foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $iso => $label)
                            <label class="flex items-center gap-1.5 text-sm">
                                {{-- ISO-8601 numbers, 1 = Monday. Carbon::SUNDAY is 0 and would match no day. --}}
                                <input type="checkbox" name="weekdays[]" value="{{ $iso }}"
                                       @checked(in_array($iso, old('weekdays', []), false))
                                       class="rounded border-slate-300">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    @error('weekdays')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror
                </div>

                <div class="mt-4 sm:w-1/2">
                    <label for="day_of_month" class="block text-sm font-medium">
                        Day of month — for monthly rules
                    </label>
                    <x-agency.form.input type="number" id="day_of_month" name="day_of_month"
                                         min="1" max="31" value="{{ old('day_of_month') }}" />
                    <p class="mt-1 text-xs text-slate-600">
                        31 still posts in February — it moves to the last day rather than
                        skipping the month.
                    </p>
                    @error('day_of_month')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror
                </div>
            </fieldset>

            <fieldset class="rounded-lg border border-slate-200 p-4">
                <legend class="px-1 text-sm font-medium">When</legend>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label for="time_of_day" class="block text-sm font-medium">Time</label>
                        <x-agency.form.input type="time" id="time_of_day" name="time_of_day"
                                             value="{{ old('time_of_day', '09:00') }}" required />
                        @error('time_of_day')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="starts_on" class="block text-sm font-medium">Starts</label>
                        <x-agency.form.input type="date" id="starts_on" name="starts_on"
                                             value="{{ old('starts_on', now()->toDateString()) }}" required />
                        @error('starts_on')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="ends_on" class="block text-sm font-medium">Ends</label>
                        <x-agency.form.input type="date" id="ends_on" name="ends_on"
                                             value="{{ old('ends_on') }}" />
                        <p class="mt-1 text-xs text-slate-600">Leave blank to run until you stop it.</p>
                        @error('ends_on')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror
                    </div>
                </div>
            </fieldset>

            <fieldset>
                <legend class="text-sm font-medium">Where it posts</legend>

                @if ($accounts->isEmpty())
                    <p class="mt-2 text-sm text-slate-600">
                        No connected accounts yet.
                        <a href="{{ route('agency.social.index') }}" class="underline">Connect one</a>
                        and it will appear here. You can still save the rule — the posts it
                        creates will need destinations adding.
                    </p>
                @else
                    {{--
                      Only publishable accounts. An account behind an expired
                      connection would fail every single week, and a standing
                      rule is the worst place to discover that.
                    --}}
                    <div class="mt-2 space-y-2 rounded-lg border border-slate-200 p-3">
                        @foreach ($accounts as $account)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="accounts[]" value="{{ $account->getKey() }}"
                                       @checked(in_array($account->getKey(), old('accounts', []), false))
                                       class="rounded border-slate-300">
                                <span>{{ $account->name ?: $account->username }}</span>
                                <span class="text-xs text-slate-500">{{ $account->provider_key }}</span>
                            </label>
                        @endforeach
                    </div>
                @endif
            </fieldset>

            <div class="flex items-center gap-3">
                <button type="submit" class="rounded-lg bg-slate-900 px-3 py-1.5 text-sm text-white">
                    Save rule
                </button>
                <a href="{{ route('agency.posts.recurring.index') }}" class="text-sm text-slate-600 hover:underline">
                    Cancel
                </a>
            </div>
        </form>
    @endif
@endsection
