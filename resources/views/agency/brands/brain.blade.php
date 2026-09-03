@extends('layouts.agency')

@section('content')

    <a href="{{ route('agency.brands.show', $brand) }}"
       class="mb-4 inline-block text-sm text-slate-600 hover:text-slate-900">
        &larr; {{ $brand->name }}
    </a>

    {{--
     | The completeness figure is shown first and explained, because output
     | quality tracks it directly. Without it people conclude the AI is poor
     | when the real answer is that it has been told almost nothing about the
     | client.
    --}}
    <div class="mb-6 rounded-xl border border-slate-200 bg-white p-5">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-sm font-semibold text-slate-700">Profile completeness</h2>
            <span class="text-2xl font-semibold">{{ $completeness }}%</span>
        </div>

        <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-slate-100">
            <div class="h-full rounded-full bg-slate-900" style="width: {{ $completeness }}%"></div>
        </div>

        <p class="mt-3 text-sm text-slate-600">
            @if ($completeness < 40)
                The AI knows very little about this client, so it will write generically.
                Filling in the audience, products and tone makes the largest difference.
            @elseif ($completeness < 80)
                Enough to work with. Adding the remaining fields sharpens the output.
            @else
                Well filled in. The AI has what it needs to sound like this brand.
            @endif
        </p>
    </div>

    <form method="POST" action="{{ route('agency.brands.brain.update', $brand) }}"
          class="max-w-3xl space-y-6">
        @csrf
        @method('PUT')

        <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-6">
            <h2 class="text-sm font-semibold">The business</h2>

            <label class="block text-sm">
                <span class="font-medium">What does this client do?</span>
                <x-agency.form.textarea name="business_description" rows="4" maxlength="5000"
                         
                          placeholder="A speciality coffee roaster in Leeds selling to cafés and at home.">{{ old('business_description', $brain->business_description) }}</x-agency.form.textarea>
            </label>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block text-sm">
                    <span class="font-medium">Industry</span>
                    <x-agency.form.input type="text" name="industry" maxlength="120"
                           value="{{ old('industry', $brain->industry) }}" />
                </label>

                <label class="block text-sm">
                    <span class="font-medium">Website</span>
                    <x-agency.form.input type="url" name="website" maxlength="255"
                           value="{{ old('website', $brain->website) }}"
                           placeholder="https://example.com" />
                </label>
            </div>
        </section>

        <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-6">
            <h2 class="text-sm font-semibold">Voice</h2>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block text-sm">
                    <span class="font-medium">Tone</span>
                    <x-agency.form.input type="text" name="brand_tone" maxlength="190"
                           value="{{ old('brand_tone', $brain->brand_tone) }}"
                           placeholder="Warm, plain-spoken, never salesy" />
                </label>

                <label class="block text-sm">
                    <span class="font-medium">Primary language</span>
                    <x-agency.form.input type="text" name="primary_language" maxlength="10"
                           value="{{ old('primary_language', $brain->primary_language ?: 'en') }}" />
                </label>
            </div>

            <label class="block text-sm">
                <span class="font-medium">Anything else about how they sound</span>
                <x-agency.form.textarea name="brand_voice_notes" rows="3" maxlength="5000">{{ old('brand_voice_notes', $brain->brand_voice_notes) }}</x-agency.form.textarea>
            </label>
        </section>

        <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-6">
            <h2 class="text-sm font-semibold">Details</h2>
            <p class="text-sm text-slate-600">One per line.</p>

            @php
                $listLabels = [
                    'target_audience' => ['Who they are talking to', 'Home baristas aged 25-45'],
                    'products' => ['Products', 'Single-origin beans'],
                    'services' => ['Services', 'Wholesale supply'],
                    'usps' => ['What makes them different', 'Roasted the day it ships'],
                    'ctas' => ['Calls to action', 'Order before Thursday'],
                    'locations' => ['Locations', 'Leeds'],
                    'competitors' => ['Competitors', ''],
                    'preferred_keywords' => ['Words to favour', 'speciality'],
                    'forbidden_words' => ['Words never to use', 'cheap'],
                    'content_themes' => ['Recurring themes', 'Behind the roastery'],
                    'goals' => ['What they want from social', 'Grow wholesale enquiries'],
                ];
            @endphp

            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ($listFields as $field)
                    @php [$label, $placeholder] = $listLabels[$field] ?? [Str::headline($field), '']; @endphp

                    <label class="block text-sm">
                        <span class="font-medium">{{ $label }}</span>
                        @if ($field === 'forbidden_words')
                            {{-- Said out loud because it is the one list with teeth:
                                 generation is checked against it and rejected. --}}
                            <span class="block text-xs text-slate-500">
                                Generated text containing these is rejected, not just discouraged.
                            </span>
                        @endif
                        <x-agency.form.textarea name="{{ $field }}" rows="4" maxlength="4000"
                                  placeholder="{{ $placeholder }}"
                                  class="font-mono">{{ old($field, implode("\n", (array) ($brain->{$field} ?? []))) }}</x-agency.form.textarea>
                    </label>
                @endforeach
            </div>
        </section>

        <x-agency.button>
            Save brand brain
        </x-agency.button>
    </form>

@endsection
