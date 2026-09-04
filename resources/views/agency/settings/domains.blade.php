@extends('layouts.agency')

@section('content')
    @unless ($entitled)
        <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Custom portal domains are part of white labelling, which is not in
            your current plan.
            <a href="{{ route('agency.billing') }}" class="font-medium underline">See plans</a>
        </div>
    @endunless

    <div class="rounded-xl border border-slate-200 bg-white p-6">
        <h2 class="text-sm font-semibold">Your clients' portal address</h2>
        <p class="mt-1 text-sm text-slate-600">
            Point a hostname at us and your clients sign in there, seeing your
            brand rather than ours.
        </p>

        @if ($entitled && $canUpdate)
            <form method="POST" action="{{ route('agency.settings.domains.store') }}"
                  class="mt-4 flex flex-wrap items-end gap-3">
                @csrf
                <div class="grow">
                    <label for="hostname" class="block text-sm font-medium">Hostname</label>
                    <input id="hostname" name="hostname" type="text" maxlength="190"
                           value="{{ old('hostname') }}"
                           placeholder="portal.youragency.com"
                           class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @error('hostname')
                        <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                    @enderror
                </div>
                <x-agency.button>Add domain</x-agency.button>
            </form>
        @endif

        @if ($domains->isEmpty())
            <p class="mt-4 text-sm text-slate-600">No domains yet.</p>
        @else
            <ul class="mt-5 space-y-4">
                @foreach ($domains as $domain)
                    <li class="rounded-lg border border-slate-200 p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-slate-900">{{ $domain->hostname }}</p>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    {{ $domain->type->label() }}
                                    @if ($domain->isVerified())
                                        &middot;
                                        <span class="text-emerald-700">Verified</span>
                                    @else
                                        &middot;
                                        <span class="text-amber-700">Not verified</span>
                                    @endif
                                    @if ($domain->ssl_status)
                                        &middot; {{ $domain->ssl_status->label() }}
                                    @endif
                                </p>
                            </div>

                            @if ($canUpdate)
                                <div class="flex shrink-0 items-center gap-3">
                                    @unless ($domain->isVerified())
                                        <form method="POST"
                                              action="{{ route('agency.settings.domains.verify', $domain) }}">
                                            @csrf
                                            <button type="submit"
                                                    class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                                                Verify
                                            </button>
                                        </form>
                                    @endunless

                                    <form method="POST"
                                          action="{{ route('agency.settings.domains.destroy', $domain) }}"
                                          onsubmit="return confirm('Remove {{ $domain->hostname }}?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                                class="text-sm font-medium text-red-700 hover:underline">
                                            Remove
                                        </button>
                                    </form>
                                </div>
                            @endif
                        </div>

                        @unless ($domain->isVerified())
                            {{--
                             | The record is shown only while unverified. Once
                             | proven it is noise, and leaving a token on screen
                             | invites somebody to "tidy up" DNS and silently
                             | break re-verification later.
                            --}}
                            <div class="mt-3 rounded-lg bg-slate-50 p-3 text-xs">
                                <p class="font-medium text-slate-700">Add this TXT record, then press Verify:</p>
                                <p class="mt-1 text-slate-600">
                                    Name <code class="rounded bg-white px-1">{{ $domain->hostname }}</code>
                                    &middot; Type <code class="rounded bg-white px-1">TXT</code>
                                </p>
                                <p class="mt-1 break-all text-slate-600">
                                    Value <code class="rounded bg-white px-1">{{ $domain->verification_token }}</code>
                                </p>
                            </div>
                        @endunless
                    </li>
                @endforeach
            </ul>
        @endif

        <p class="mt-5 text-xs text-slate-500">
            {{-- Said plainly: the application cannot issue certificates. --}}
            A certificate is issued by the server once DNS points here and the
            domain is verified. Until then the domain will not open in a browser.
        </p>
    </div>
@endsection
