@extends('layouts.agency')

@section('content')
    <div class="max-w-3xl">
        <h1 class="text-lg font-semibold">Developer apps</h1>
        <p class="mt-1 text-sm text-slate-600">
            Connect accounts through your own app on each network instead of ours. Your app
            has its own rate limits, so how many clients you serve stops depending on how
            many everyone else does.
        </p>
        <p class="mt-2 text-sm text-slate-600">
            Leave a network blank and connections use the platform app, which is the right
            choice until you have a reason to change it.
        </p>

        <div class="mt-4 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
            {{--
              Said plainly, because somebody will look for a way to check what
              they typed, and not finding one should read as deliberate rather
              than broken.
            --}}
            Once saved, a client ID and secret are never shown again — not even partly. If
            you lose them, generate new ones on the network and paste them in here.
        </div>

        @if ($credentials->isEmpty())
            <p class="mt-6 text-sm text-slate-600">No apps of your own yet.</p>
        @else
            <div class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white">
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 text-left text-slate-600">
                        <tr>
                            <th scope="col" class="px-5 py-3 font-medium">Network</th>
                            <th scope="col" class="px-5 py-3 font-medium">App</th>
                            <th scope="col" class="px-5 py-3 font-medium">State</th>
                            <th scope="col" class="px-5 py-3 font-medium"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @foreach ($credentials as $credential)
                            <tr>
                                <td class="px-5 py-3">{{ $credential['provider_key'] }}</td>
                                <td class="px-5 py-3">
                                    {{ $credential['label'] }}
                                    @if ($credential['in_use'])
                                        <span class="ml-1 rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
                                            in use
                                        </span>
                                    @endif
                                </td>
                                <td class="px-5 py-3">
                                    @if ($credential['is_active'])
                                        <span class="rounded bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">Active</span>
                                    @else
                                        <span class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">Off</span>
                                    @endif

                                    @if ($credential['last_verify_error'])
                                        <p class="mt-1 text-xs text-red-700">{{ $credential['last_verify_error'] }}</p>
                                    @endif
                                </td>
                                <td class="px-5 py-3">
                                    <div class="flex items-center justify-end gap-2">
                                        <form method="POST"
                                              action="{{ route('agency.social.credentials.toggle', $credential['id']) }}">
                                            @csrf
                                            @method('PUT')
                                            <button type="submit"
                                                    class="rounded-lg border border-slate-300 px-3 py-1 text-xs hover:bg-slate-50">
                                                {{ $credential['is_active'] ? 'Turn off' : 'Turn on' }}
                                            </button>
                                        </form>

                                        <form method="POST"
                                              action="{{ route('agency.social.credentials.destroy', $credential['id']) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                    class="rounded-lg border border-slate-300 px-3 py-1 text-xs text-red-700 hover:bg-red-50">
                                                Remove
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <form method="POST" action="{{ route('agency.social.credentials.store') }}"
              class="mt-8 space-y-4 rounded-xl border border-slate-200 bg-white p-6">
            @csrf

            <h2 class="text-sm font-semibold">Add an app</h2>

            <div>
                <label for="provider_key" class="block text-sm font-medium">Network</label>
                <x-agency.form.select id="provider_key" name="provider_key" required>
                    @foreach ($providers as $provider)
                        <option value="{{ $provider }}" @selected(old('provider_key') === $provider)>
                            {{ $provider }}
                        </option>
                    @endforeach
                </x-agency.form.select>
            </div>

            <div>
                <label for="label" class="block text-sm font-medium">Name</label>
                <x-agency.form.input id="label" name="label" value="{{ old('label') }}" required />
                <p class="mt-1 text-xs text-slate-600">
                    How you will recognise this app later. It is the only part you will see again.
                </p>
            </div>

            {{--
              No old() on either field. Repopulating after a validation failure
              would put the secret back into the HTML, into the browser's form
              cache, and into any proxy that logs a response body.
            --}}
            <div>
                <label for="client_id" class="block text-sm font-medium">Client ID</label>
                <input id="client_id" name="client_id" type="password" autocomplete="off" required
                       class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>

            <div>
                <label for="client_secret" class="block text-sm font-medium">Client secret</label>
                <input id="client_secret" name="client_secret" type="password" autocomplete="off" required
                       class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>

            @error('provider_key')
                <p class="text-sm text-rose-700">{{ $message }}</p>
            @enderror
            @error('label')
                <p class="text-sm text-rose-700">{{ $message }}</p>
            @enderror
            @error('client_id')
                <p class="text-sm text-rose-700">{{ $message }}</p>
            @enderror
            @error('client_secret')
                <p class="text-sm text-rose-700">{{ $message }}</p>
            @enderror

            <x-agency.button>Save app</x-agency.button>
        </form>
    </div>
@endsection
