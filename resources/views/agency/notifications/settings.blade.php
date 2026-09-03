@extends('layouts.agency')

@section('content')

    <a href="{{ route('agency.notifications.index') }}"
       class="mb-4 inline-block text-sm text-slate-600 hover:text-slate-900">
        &larr; Notifications
    </a>

    <form method="POST" action="{{ route('agency.notifications.settings.update') }}"
          class="max-w-2xl rounded-xl border border-slate-200 bg-white p-6">
        @csrf
        @method('PUT')

        <p class="mb-5 text-sm text-slate-600">
            These control what reaches <strong>you</strong>. Everyone on the team chooses their own.
        </p>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-slate-500">
                    <tr>
                        <th class="pb-2 font-medium">Tell me when</th>
                        @foreach ($channels as $channel)
                            <th class="pb-2 pl-6 font-medium">
                                {{ $channel === 'mail' ? 'Email' : 'In app' }}
                            </th>
                        @endforeach
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-100">
                    @foreach ($events as $event)
                        <tr>
                            <td class="py-3 pr-4">
                                {{ $event['label'] }}
                                @if ($event['key'] === 'post.publish_failed')
                                    {{-- Said out loud rather than enforced. Someone who
                                         deliberately silences this should know what they
                                         are choosing; refusing the choice outright would
                                         be the product overruling its user. --}}
                                    <span class="mt-0.5 block text-xs text-amber-700">
                                        Worth keeping on — the client usually notices a missing post first.
                                    </span>
                                @endif
                            </td>

                            @foreach ($channels as $channel)
                                <td class="py-3 pl-6">
                                    <label class="inline-flex items-center gap-2">
                                        {{--
                                         | Every combination is written on save, not just
                                         | the checked ones: an unchecked box submits
                                         | nothing, and absence here means OFF, whereas
                                         | absence in the database means "use the
                                         | default". Recording the choice explicitly stops
                                         | a later change to a default silently
                                         | overriding what someone picked.
                                        --}}
                                        <input type="checkbox"
                                               name="prefs[{{ $event['key'] }}][{{ $channel }}]"
                                               value="1"
                                               @checked(in_array($channel, $event['channels'], true))
                                               class="rounded border-slate-300">
                                        <span class="sr-only">
                                            {{ $event['label'] }} via {{ $channel === 'mail' ? 'email' : 'in app' }}
                                        </span>
                                    </label>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <button type="submit"
                class="mt-6 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
            Save settings
        </button>

        <p class="mt-3 text-xs text-slate-500">
            Client-facing messages are not listed here. Those go to the people you have invited
            to the client portal, and are controlled by their brand access rather than by you.
        </p>
    </form>

@endsection
