@extends('layouts.admin')

@section('content')

    {{--
     | NOTE FOR ANYONE EXTENDING THIS PAGE.
     |
     | Nothing here reads social_app_credentials, social_connections tokens, or
     | any other agency secret, and nothing added later may either. Agencies
     | supply their own provider credentials on the understanding that platform
     | staff cannot see them; a "just for debugging" field would break that, and
     | would do it silently. See docs/05-SOCIAL-PROVIDERS.md section 11 and
     | docs/10-SECURITY.md section 5. A test asserts this.
    --}}

    <div class="grid gap-6 lg:grid-cols-3">

        <div class="space-y-6 lg:col-span-2">

            {{-- Lifecycle --}}
            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-sm font-semibold text-slate-700">Lifecycle</h2>

                <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-slate-500">Status</dt>
                        <dd class="font-medium">{{ $tenant->status->label() }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Created</dt>
                        <dd class="font-medium">{{ $tenant->created_at?->format('j M Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Trial ends</dt>
                        <dd class="font-medium">{{ $tenant->trial_ends_at?->format('j M Y') ?? '—' }}</dd>
                    </div>
                </dl>

                @can('platform.tenants.manage')
                    <form method="POST"
                          action="{{ $tenant->permitsProductAccess() ? route('admin.tenants.suspend', $tenant) : route('admin.tenants.reactivate', $tenant) }}"
                          class="mt-4 border-t border-slate-100 pt-4">
                        @csrf
                        <label class="block text-sm">
                            <span class="font-medium text-slate-700">
                                Reason for {{ $tenant->permitsProductAccess() ? 'suspending' : 'reactivating' }}
                            </span>
                            <input type="text" name="reason" required minlength="5" maxlength="500"
                                   placeholder="Recorded in the audit trail against your name"
                                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        </label>

                        <button type="submit"
                                class="mt-3 rounded-lg px-4 py-2 text-sm font-medium text-white
                                       {{ $tenant->permitsProductAccess() ? 'bg-red-700 hover:bg-red-800' : 'bg-emerald-700 hover:bg-emerald-800' }}">
                            {{ $tenant->permitsProductAccess() ? 'Suspend agency' : 'Reactivate agency' }}
                        </button>

                        <p class="mt-2 text-xs text-slate-500">
                            Suspending stops product access. Nothing is deleted, and reactivating restores it.
                        </p>
                    </form>
                @endcan
            </section>

            {{-- Entitlements --}}
            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-sm font-semibold text-slate-700">Limits</h2>

                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-slate-500">
                            <tr>
                                <th class="py-2 font-medium">Key</th>
                                <th class="py-2 font-medium text-right">Used</th>
                                <th class="py-2 pr-4 font-medium text-right">Allowance</th>
                                <th class="py-2 font-medium">Source</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($usage as $row)
                                <tr>
                                    <td class="py-2 font-mono text-xs">{{ $row['key'] }}</td>
                                    <td class="py-2 text-right">{{ number_format($row['used']) }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $row['limit'] }}</td>
                                    <td class="py-2">
                                        <span class="rounded px-1.5 py-0.5 text-xs
                                            {{ $row['source'] === 'override' ? 'bg-amber-100 text-amber-900' : 'text-slate-500' }}">
                                            {{ $row['source'] }}
                                        </span>
                                    </td>
                                    <td class="py-2 text-right">
                                        @if ($row['source'] === 'override')
                                            @can('platform.entitlements.override')
                                                <form method="POST"
                                                      action="{{ route('admin.tenants.entitlements.destroy', [$tenant, $row['key']]) }}"
                                                      class="flex items-center justify-end gap-2">
                                                    @csrf
                                                    @method('DELETE')
                                                    <input type="text" name="reason" required minlength="5"
                                                           placeholder="Reason"
                                                           class="w-40 rounded border border-slate-300 px-2 py-1 text-xs">
                                                    <button type="submit" class="text-xs underline">Remove</button>
                                                </form>
                                            @endcan
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @can('platform.entitlements.override')
                    <form method="POST" action="{{ route('admin.tenants.entitlements.store', $tenant) }}"
                          class="mt-4 grid gap-3 border-t border-slate-100 pt-4 sm:grid-cols-2">
                        @csrf

                        <label class="text-sm">
                            <span class="block font-medium text-slate-700">Key</span>
                            <select name="key" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                @foreach ($entitlementKeys as $key)
                                    <option value="{{ $key }}">{{ $key }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="text-sm">
                            <span class="block font-medium text-slate-700">Type</span>
                            <select name="value_type" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                <option value="limit">limit</option>
                                <option value="boolean">boolean</option>
                                <option value="unlimited">unlimited</option>
                            </select>
                        </label>

                        <label class="text-sm">
                            <span class="block font-medium text-slate-700">Value</span>
                            <input type="number" name="value" min="0"
                                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <span class="mt-1 block text-xs text-slate-500">Leave blank only for unlimited.</span>
                        </label>

                        <label class="text-sm">
                            <span class="block font-medium text-slate-700">Expires (optional)</span>
                            <input type="datetime-local" name="expires_at"
                                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        </label>

                        <label class="text-sm sm:col-span-2">
                            <span class="block font-medium text-slate-700">Reason</span>
                            <input type="text" name="reason" required minlength="5" maxlength="500"
                                   placeholder="Why this agency gets a different limit"
                                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        </label>

                        <div class="sm:col-span-2">
                            <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white">
                                Save override
                            </button>
                        </div>
                    </form>
                @endcan
            </section>

            {{-- Team --}}
            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-sm font-semibold text-slate-700">Team</h2>
                <ul class="mt-3 divide-y divide-slate-100 text-sm">
                    @forelse ($members as $member)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <span class="truncate">
                                {{ $member->name }}
                                <span class="block text-xs text-slate-500">{{ $member->email }}</span>
                            </span>
                            <span class="shrink-0 text-xs text-slate-500">{{ $member->pivot->status }}</span>
                        </li>
                    @empty
                        <li class="py-2 text-slate-500">No members.</li>
                    @endforelse
                </ul>
            </section>
        </div>

        {{-- Sidebar --}}
        <div class="space-y-6">

            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-sm font-semibold text-slate-700">Subscription</h2>
                @if ($subscription === null)
                    <p class="mt-2 text-sm text-slate-500">No subscription.</p>
                @else
                    <dl class="mt-3 space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Plan</dt>
                            <dd class="font-medium">{{ $subscription->plan_name ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Status</dt>
                            <dd class="font-medium">{{ $subscription->status }}</dd>
                        </div>
                    </dl>
                @endif
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-sm font-semibold text-slate-700">AI credits</h2>
                <p class="mt-2 text-2xl font-semibold">
                    {{ number_format((int) ($credits->balance ?? 0)) }}
                </p>
                <p class="text-xs text-slate-500">
                    {{ number_format((int) ($credits->reserved ?? 0)) }} reserved
                </p>

                @can('platform.credits.adjust')
                    <form method="POST" action="{{ route('admin.tenants.credits.store', $tenant) }}" class="mt-4 space-y-3">
                        @csrf
                        <label class="block text-sm">
                            <span class="font-medium text-slate-700">Adjustment</span>
                            <input type="number" name="delta" required
                                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <span class="mt-1 block text-xs text-slate-500">Negative removes credits.</span>
                        </label>
                        <label class="block text-sm">
                            <span class="font-medium text-slate-700">Reason</span>
                            <input type="text" name="reason" required minlength="5"
                                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        </label>
                        <button type="submit" class="w-full rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white">
                            Apply adjustment
                        </button>
                    </form>
                @endcan

                @if ($creditHistory->isNotEmpty())
                    <ul class="mt-4 space-y-1 border-t border-slate-100 pt-3 text-xs">
                        @foreach ($creditHistory as $entry)
                            <li class="flex justify-between gap-2">
                                <span class="truncate text-slate-500">{{ $entry->type }}</span>
                                <span class="shrink-0 font-medium">{{ $entry->amount > 0 ? '+' : '' }}{{ $entry->amount }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            @can('platform.impersonate')
                <section class="rounded-xl border border-amber-300 bg-amber-50 p-5">
                    <h2 class="text-sm font-semibold text-amber-900">Impersonate</h2>
                    <p class="mt-1 text-xs text-amber-900">
                        You will act as this user until you exit, or for
                        {{ \App\Domain\Platform\Models\ImpersonationSession::timeoutMinutes() }} minutes.
                        Credentials, billing and destructive actions stay blocked.
                    </p>

                    @if ($impersonationTargets->isEmpty())
                        <p class="mt-3 text-xs text-amber-900">No impersonable users in this agency.</p>
                    @else
                        @foreach ($impersonationTargets as $target)
                            <form method="POST" action="{{ route('admin.impersonation.start', $target) }}"
                                  class="mt-3 space-y-2 border-t border-amber-200 pt-3">
                                @csrf
                                <p class="text-sm font-medium text-amber-950">{{ $target->name }}</p>
                                <input type="text" name="reason" required minlength="10" maxlength="500"
                                       placeholder="What are you investigating?"
                                       class="w-full rounded-lg border border-amber-300 px-3 py-2 text-sm">
                                <button type="submit"
                                        class="w-full rounded-lg bg-amber-900 px-3 py-2 text-sm font-medium text-white hover:bg-amber-950">
                                    Act as {{ $target->name }}
                                </button>
                            </form>
                        @endforeach
                    @endif
                </section>
            @endcan

            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-sm font-semibold text-slate-700">Recent impersonations</h2>
                @if ($impersonations->isEmpty())
                    <p class="mt-2 text-sm text-slate-500">None recorded.</p>
                @else
                    <ul class="mt-3 space-y-2 text-xs">
                        @foreach ($impersonations as $session)
                            <li>
                                <span class="font-medium">{{ $session->superAdmin?->name ?? 'Unknown' }}</span>
                                <span class="text-slate-500">
                                    · {{ $session->started_at->format('j M H:i') }}
                                    · {{ $session->isOpen() ? 'open' : $session->elapsedMinutes().' min' }}
                                </span>
                                <span class="block text-slate-500">{{ $session->reason }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @can('platform.audit.view')
                    <a href="{{ route('admin.audit.index', ['tenant' => $tenant->getKey()]) }}"
                       class="mt-3 inline-block text-xs underline">Full audit trail</a>
                @endcan
            </section>
        </div>
    </div>

@endsection
