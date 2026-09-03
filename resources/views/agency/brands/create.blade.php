@extends('layouts.agency')

@section('content')
    <form method="POST" action="{{ route('agency.brands.store') }}"
          class="max-w-xl space-y-4 rounded-xl border border-slate-200 bg-white p-6">
        @csrf

        <div>
            <label for="name" class="block text-sm font-medium">Brand name</label>
            <x-agency.form.input id="name" name="name" value="{{ old('name') }}" required autofocus />
        </div>

        <div>
            <label for="industry" class="block text-sm font-medium">Industry</label>
            <x-agency.form.input id="industry" name="industry" value="{{ old('industry') }}" />
        </div>

        <div>
            <label for="website" class="block text-sm font-medium">Website</label>
            <x-agency.form.input id="website" name="website" type="url" value="{{ old('website') }}" />
        </div>

        <div>
            <label for="timezone" class="block text-sm font-medium">Timezone</label>
            <x-agency.form.input id="timezone" name="timezone" value="{{ old('timezone') }}"
                   placeholder="Inherits the agency timezone" />
            {{-- Scheduling reads this, so it is worth saying why it matters. --}}
            <p class="mt-1 text-xs text-slate-600">
                Posts are scheduled in this timezone. Leave blank to use the agency's.
            </p>
        </div>

        <div>
            <label for="contact_email" class="block text-sm font-medium">Client contact email</label>
            <x-agency.form.input id="contact_email" name="contact_email" type="email" value="{{ old('contact_email') }}" />
        </div>

        <div class="flex items-center gap-3 pt-2">
            <x-agency.button>
                Create brand
            </x-agency.button>
            <a href="{{ route('agency.brands.index') }}" class="text-sm text-slate-600 hover:underline">Cancel</a>
        </div>
    </form>
@endsection
