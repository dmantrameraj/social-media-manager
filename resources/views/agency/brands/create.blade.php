@extends('layouts.agency')

@section('content')
    <form method="POST" action="{{ route('agency.brands.store') }}"
          class="max-w-xl space-y-4 rounded-xl border border-slate-200 bg-white p-6">
        @csrf

        <div>
            <label for="name" class="block text-sm font-medium">Brand name</label>
            <input id="name" name="name" value="{{ old('name') }}" required autofocus
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>

        <div>
            <label for="industry" class="block text-sm font-medium">Industry</label>
            <input id="industry" name="industry" value="{{ old('industry') }}"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>

        <div>
            <label for="website" class="block text-sm font-medium">Website</label>
            <input id="website" name="website" type="url" value="{{ old('website') }}"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>

        <div>
            <label for="timezone" class="block text-sm font-medium">Timezone</label>
            <input id="timezone" name="timezone" value="{{ old('timezone') }}"
                   placeholder="Inherits the agency timezone"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            {{-- Scheduling reads this, so it is worth saying why it matters. --}}
            <p class="mt-1 text-xs text-slate-600">
                Posts are scheduled in this timezone. Leave blank to use the agency's.
            </p>
        </div>

        <div>
            <label for="contact_email" class="block text-sm font-medium">Client contact email</label>
            <input id="contact_email" name="contact_email" type="email" value="{{ old('contact_email') }}"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit"
                    class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                Create brand
            </button>
            <a href="{{ route('agency.brands.index') }}" class="text-sm text-slate-600 hover:underline">Cancel</a>
        </div>
    </form>
@endsection
