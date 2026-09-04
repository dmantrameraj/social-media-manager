@extends('layouts.agency')

@section('content')
    {{--
     | White labelling. It matters most in the CLIENT PORTAL: somebody logging
     | in to approve their posts should see the agency they hired, not the
     | vendor behind it.
    --}}

    @unless ($entitled)
        {{--
         | Shown rather than hidden. Somebody who was sold this feature, or is
         | considering it, should find the setting and be told plainly why it
         | is inactive -- not hunt for a screen that appears not to exist.
        --}}
        <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            White labelling is not part of your current plan. Anything already
            saved here is kept and will apply again if you upgrade.
            <a href="{{ route('agency.billing') }}" class="font-medium underline">See plans</a>
        </div>
    @endunless

    <form method="POST" action="{{ route('agency.settings.branding.update') }}"
          class="rounded-xl border border-slate-200 bg-white p-6">
        @csrf
        @method('PUT')

        <h2 class="text-sm font-semibold">What your clients see</h2>
        <p class="mt-1 text-sm text-slate-600">
            Leave anything blank to use the platform default.
        </p>

        <div class="mt-5 grid gap-5 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label for="app_name" class="block text-sm font-medium">Product name</label>
                <input id="app_name" name="app_name" type="text" maxlength="120"
                       value="{{ old('app_name', $branding?->app_name) }}"
                       placeholder="{{ config('branding.app_name') }}"
                       @disabled(! $entitled || ! $canUpdate)
                       class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50">
                @error('app_name')
                    <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror
            </div>

            <div class="sm:col-span-2">
                <label for="support_email" class="block text-sm font-medium">Support email</label>
                <input id="support_email" name="support_email" type="email" maxlength="190"
                       value="{{ old('support_email', $branding?->support_email) }}"
                       placeholder="{{ config('branding.support_email') }}"
                       @disabled(! $entitled || ! $canUpdate)
                       class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50">
                <p class="mt-1 text-xs text-slate-500">
                    Where your clients are told to ask for help.
                </p>
                @error('support_email')
                    <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror
            </div>

            @foreach ([
                'primary_color' => ['Primary colour', config('branding.colors.primary')],
                'secondary_color' => ['Secondary colour', config('branding.colors.secondary')],
            ] as $field => [$label, $default])
                <div>
                    <label for="{{ $field }}" class="block text-sm font-medium">{{ $label }}</label>
                    <div class="mt-1 flex items-center gap-2">
                        {{--
                         | A text field, not only a colour picker. A picker
                         | cannot express "unset", and clearing the value is how
                         | an agency goes back to the default.
                        --}}
                        <input id="{{ $field }}" name="{{ $field }}" type="text" maxlength="7"
                               value="{{ old($field, $branding?->{$field}) }}"
                               placeholder="{{ $default }}"
                               @disabled(! $entitled || ! $canUpdate)
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50">
                        <span class="h-9 w-9 shrink-0 rounded-lg border border-slate-200"
                              style="background-color: {{ $branding?->{$field} ?: $default }}"></span>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">Six-digit hex, like {{ $default }}.</p>
                    @error($field)
                        <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach
        </div>

        @if ($entitled && $canUpdate)
            <div class="mt-6">
                <x-agency.button>Save branding</x-agency.button>
            </div>
        @endif
    </form>
@endsection
