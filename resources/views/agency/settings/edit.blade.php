@extends('layouts.agency')

@section('content')

    <form method="POST" action="{{ route('agency.settings.update') }}"
          class="max-w-2xl space-y-6">
        @csrf
        @method('PUT')

        <fieldset @unless ($canUpdate) disabled @endunless
                  class="space-y-4 rounded-xl border border-slate-200 bg-white p-6">
            <h2 class="text-sm font-semibold">Workspace</h2>

            <label class="block text-sm">
                <span class="font-medium">Agency name</span>
                <input type="text" name="name" required maxlength="160"
                       value="{{ old('name', $tenant->name) }}"
                       class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50">
            </label>

            <label class="block text-sm">
                <span class="font-medium">Timezone</span>
                <select name="timezone"
                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-50">
                    @foreach ($timezones as $timezone)
                        <option value="{{ $timezone }}" @selected(old('timezone', $tenant->timezone) === $timezone)>
                            {{ $timezone }}
                        </option>
                    @endforeach
                </select>

                {{--
                 | Said plainly, because somebody changing this will otherwise
                 | assume it fixed the brands they already have. A brand's
                 | timezone is snapshotted when it is created, so scheduling
                 | never has to walk back to the agency on a hot path -- which
                 | also means changing this cannot reach backwards.
                --}}
                <span class="mt-1 block text-xs text-slate-500">
                    Applies to brands created from now on. Existing brands keep the
                    timezone they were created with — change those on the brand itself.
                </span>
            </label>
        </fieldset>

        @if ($canUpdate)
            <button type="submit"
                    class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                Save settings
            </button>
        @endif
    </form>

@endsection
