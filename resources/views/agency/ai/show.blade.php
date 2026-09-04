@extends('layouts.agency')

@section('content')
    <div class="mb-4">
        <a href="{{ route('agency.ai.index') }}" class="text-sm text-slate-600 hover:underline">
            &larr; All tools
        </a>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-sm font-medium text-slate-900">{{ $feature->label() }}</h2>
                    <p class="mt-1 text-sm text-slate-600">{{ $feature->description() }}</p>
                </div>
                <span class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
                    {{ $cost.' '.\Illuminate\Support\Str::plural('credit', $cost) }}
                </span>
            </div>

            <form method="POST" action="{{ route('agency.ai.generate', $feature->key()) }}" class="mt-5">
                @csrf

                <div>
                    <label for="customer" class="block text-sm font-medium">Brand</label>
                    <select id="customer" name="customer" required
                            class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($brands as $brand)
                            <option value="{{ $brand->getKey() }}"
                                    @selected((int) old('customer', $selectedBrand ?? null) === $brand->getKey())>
                                {{ $brand->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('customer')
                        <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                    @enderror
                </div>

                {{--
                 | Built from the feature's own inputFields(). A form described
                 | in a second list here would drift the moment a feature read a
                 | key nobody added, and the symptom -- a field silently ignored
                 | -- reads as a model problem rather than a wiring one.
                --}}
                @foreach ($feature->inputFields() as $field)
                    <div class="mt-4">
                        <label for="{{ $field['name'] }}" class="block text-sm font-medium">
                            {{ $field['label'] }}
                            @unless ($field['required'] ?? false)
                                <span class="font-normal text-slate-500">(optional)</span>
                            @endunless
                        </label>

                        @if ($field['type'] === 'textarea')
                            <textarea id="{{ $field['name'] }}" name="{{ $field['name'] }}" rows="8"
                                      @required($field['required'] ?? false)
                                      class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                            >{{ old($field['name']) }}</textarea>
                        @else
                            <input id="{{ $field['name'] }}" name="{{ $field['name'] }}"
                                   type="{{ $field['type'] === 'number' ? 'number' : ($field['type'] === 'date' ? 'date' : 'text') }}"
                                   value="{{ old($field['name']) }}"
                                   @required($field['required'] ?? false)
                                   @isset($field['min']) min="{{ $field['min'] }}" @endisset
                                   @isset($field['max']) max="{{ $field['max'] }}" @endisset
                                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @endif

                        @isset($field['help'])
                            <p class="mt-1 text-xs text-slate-500">{{ $field['help'] }}</p>
                        @endisset

                        @error($field['name'])
                            <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach

                <div class="mt-5 flex items-center gap-3">
                    <x-agency.button>Generate</x-agency.button>
                    <span class="text-sm text-slate-500">
                        {{ $account->available() }} credits available
                    </span>
                </div>
            </form>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-medium text-slate-900">Result</h2>

            @if ($result === null)
                <p class="mt-2 text-sm text-slate-600">
                    Nothing yet. Fill in the form and generate.
                </p>
            @else
                {{--
                 | Each feature parses into its own shape, so the result is
                 | rendered generically rather than assuming one key. Scalars
                 | print; lists become a list.
                --}}
                @foreach ($result as $key => $value)
                    <div class="mt-4">
                        @if (count($result) > 1)
                            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">
                                {{ \Illuminate\Support\Str::headline((string) $key) }}
                            </p>
                        @endif

                        @if (is_array($value))
                            <ul class="mt-1 space-y-2">
                                @foreach ($value as $item)
                                    <li class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-800">
                                        @if (is_array($item))
                                            <pre class="overflow-x-auto whitespace-pre-wrap text-xs">{{ json_encode($item, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                        @else
                                            {{ $item }}
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="mt-1 whitespace-pre-wrap rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-800">{{ $value }}</p>
                        @endif
                    </div>
                @endforeach

                <p class="mt-4 text-xs text-slate-500">
                    Check anything factual before it goes out. The model works from
                    the Brand Brain and can still be wrong.
                </p>
            @endif
        </div>
    </div>
@endsection
