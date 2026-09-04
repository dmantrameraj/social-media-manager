@extends('layouts.agency')

@section('content')
    {{--
     | The connected destinations. Until this screen existed the only way to get
     | a publishable account into the system was to insert a row by hand, so
     | every other screen in the product -- the composer, the calendar, the
     | approval queue -- led to a dead end.
    --}}

    @if ($brands->isEmpty())
        @include('agency.partials.empty', [
            'title' => 'No brands yet',
            'description' => 'A connected account belongs to a brand, so create one first.',
        ])
    @else
        @if ($canConnect)
            <div class="mb-6 rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-sm font-medium text-slate-900">Connect an account</h2>
                <p class="mt-1 text-sm text-slate-600">
                    Choose the brand this account works for, then pick a network.
                    You will confirm which pages or profiles to use afterwards.
                </p>

                {{--
                 | GET, because this leg only sends somebody to the provider --
                 | nothing is written until they come back and choose. The brand
                 | travels as a query parameter and is re-checked server-side
                 | against this tenant; a value typed into the URL by hand gets a
                 | 404 from the tenant scope, not a connected account.
                 |
                 | One form, one button per network, using formaction. The brand
                 | must be chosen before the network, and a separate form per
                 | provider would mean a separate brand select per provider.
                --}}
                <form method="GET" class="mt-4 flex flex-wrap items-end gap-3">
                    <div>
                        <label for="customer" class="block text-sm font-medium">Brand</label>
                        <select id="customer" name="customer" required
                                class="mt-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            @foreach ($brands as $brand)
                                <option value="{{ $brand->getKey() }}">{{ $brand->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    @forelse ($providers as $provider)
                        <button type="submit"
                                formaction="{{ route('agency.social.connect', $provider) }}"
                                class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50">
                            {{ config("social.providers.{$provider}.name", $provider) }}
                        </button>
                    @empty
                        {{--
                         | Enabled is a commercial decision, not a technical one:
                         | X ships disabled because its write quota has to be
                         | bought. Saying so is better than an empty row that
                         | looks like a bug.
                        --}}
                        <p class="text-sm text-slate-600">
                            No networks are switched on for this installation yet.
                        </p>
                    @endforelse
                </form>
            </div>
        @endif

        @if ($accounts->isEmpty())
            @include('agency.partials.empty', [
                'title' => 'No accounts connected',
                'description' => 'Connect a network above to start scheduling posts.',
            ])
        @else
            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">Account</th>
                            <th scope="col" class="px-4 py-3 font-medium">Network</th>
                            <th scope="col" class="px-4 py-3 font-medium">Brand</th>
                            <th scope="col" class="px-4 py-3 font-medium">Status</th>
                            @if ($canDisconnect)
                                <th scope="col" class="px-4 py-3 font-medium">
                                    <span class="sr-only">Actions</span>
                                </th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($accounts as $account)
                            <tr>
                                <td class="px-4 py-3">
                                    <span class="font-medium text-slate-900">{{ $account->name }}</span>
                                    @if ($account->username)
                                        <span class="text-slate-500">&#64;{{ $account->username }}</span>
                                    @endif
                                    <span class="block text-xs text-slate-500">
                                        {{ $account->account_type->label() }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    {{ config("social.providers.{$account->provider_key}.name", $account->provider_key) }}
                                </td>
                                <td class="px-4 py-3">{{ $account->customer?->name }}</td>
                                <td class="px-4 py-3">
                                    {{-- Health is the answer to "why will this not publish?" --}}
                                    <span @class([
                                        'rounded-full px-2 py-0.5 text-xs font-medium',
                                        'bg-emerald-50 text-emerald-700' => $account->status->canPublish(),
                                        'bg-amber-50 text-amber-700' => ! $account->status->canPublish(),
                                    ])>
                                        {{ ucfirst(str_replace('_', ' ', $account->status->value)) }}
                                    </span>
                                </td>
                                @if ($canDisconnect)
                                    <td class="px-4 py-3 text-right">
                                        {{--
                                         | Disconnecting keeps the account row and
                                         | everything published through it. The
                                         | post history is what an agency gets
                                         | asked about months later.
                                        --}}
                                        @if ($account->status->canPublish())
                                            <form method="POST"
                                                  action="{{ route('agency.social.destroy', $account) }}"
                                                  onsubmit="return confirm('Stop publishing to {{ $account->name }}? Past posts are kept.')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit"
                                                        class="text-sm font-medium text-red-700 hover:underline">
                                                    Disconnect
                                                </button>
                                            </form>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
@endsection
