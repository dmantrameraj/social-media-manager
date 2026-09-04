@extends('layouts.agency')

@section('content')
    {{--
     | The destination picker. A grant is not a destination: one Meta
     | authorisation commonly returns every Page the person administers, and
     | attaching all of them automatically is how a client's post ends up on
     | somebody else's Page.
    --}}

    <div class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="text-sm font-medium text-slate-900">
            Choose what to connect
        </h2>
        <p class="mt-1 text-sm text-slate-600">
            {{ config("social.providers.{$connection->provider_key}.name", $connection->provider_key) }}
            returned {{ $discovered->count() }}
            {{ str('account')->plural($discovered->count()) }}
            @if ($connection->name)
                for {{ $connection->name }}
            @endif
            . Only the ones you tick can be posted to.
        </p>

        @if ($discovered->isEmpty())
            {{--
             | A valid grant with nothing behind it is ordinary, not an error --
             | typically a personal account where the network only publishes to
             | Pages. Saying which is more useful than an empty checkbox list.
            --}}
            <p class="mt-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
                This account does not administer anything we can publish to.
                Most networks only allow posting to a page or business profile,
                not to a personal account.
            </p>

            <div class="mt-4">
                <a href="{{ route('agency.social.index') }}"
                   class="text-sm font-medium text-slate-700 hover:underline">
                    Back to accounts
                </a>
            </div>
        @else
            <form method="POST" action="{{ route('agency.social.store', $connection) }}" class="mt-4">
                @csrf

                <div>
                    <label for="customer" class="block text-sm font-medium">Brand</label>
                    <select id="customer" name="customer" required
                            class="mt-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($brands as $brand)
                            <option value="{{ $brand->getKey() }}"
                                    @selected((int) old('customer', $selectedBrand) === $brand->getKey())>
                                {{ $brand->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('customer')
                        <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                    @enderror
                </div>

                <fieldset class="mt-5">
                    <legend class="text-sm font-medium">Accounts</legend>

                    {{--
                     | Nothing is ticked by default. A pre-ticked list turns
                     | "connect my agency Page" into "connect all fourteen
                     | Pages I happen to administer, including my own clients'".
                    --}}
                    <div class="mt-2 divide-y divide-slate-100 rounded-lg border border-slate-200">
                        @foreach ($discovered as $account)
                            <label class="flex items-start gap-3 px-4 py-3">
                                <input type="checkbox" name="accounts[]"
                                       value="{{ $account->externalId }}"
                                       @checked(in_array($account->externalId, old('accounts', []), true))
                                       class="mt-1 rounded border-slate-300">
                                <span>
                                    <span class="block text-sm font-medium text-slate-900">
                                        {{ $account->name }}
                                    </span>
                                    <span class="block text-xs text-slate-500">
                                        {{ $account->type->label() }}
                                        @if ($account->username)
                                            &middot; &#64;{{ $account->username }}
                                        @endif
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    @error('accounts')
                        <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                    @enderror
                </fieldset>

                <div class="mt-5 flex items-center gap-3">
                    <x-agency.button>
                        Connect selected
                    </x-agency.button>

                    <a href="{{ route('agency.social.index') }}"
                       class="text-sm font-medium text-slate-700 hover:underline">
                        Cancel
                    </a>
                </div>
            </form>
        @endif
    </div>
@endsection
