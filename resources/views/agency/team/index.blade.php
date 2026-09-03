@extends('layouts.agency')

@section('content')
    <p class="mb-4 text-sm text-slate-600">
        {{ $used }} of {{ $limit->isUnlimited() ? 'unlimited' : $limit->limit() }} seats used.
    </p>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table class="w-full text-sm">
            <thead class="border-b border-slate-200 text-left text-slate-600">
                <tr>
                    <th scope="col" class="px-5 py-3 font-medium">Member</th>
                    <th scope="col" class="px-5 py-3 font-medium">Email</th>
                    <th scope="col" class="px-5 py-3 font-medium">Role</th>
                    <th scope="col" class="px-5 py-3 font-medium">Status</th>
                    <th scope="col" class="px-5 py-3 font-medium"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                @foreach ($members as $member)
                    @php
                        $isOwner = $ownerId !== null && $member->user_id === $ownerId;
                        $isSelf = $member->user_id === $currentUserId;
                        // Neither can be acted on, and the row says why rather
                        // than showing a button that always fails.
                        $locked = $isOwner || $isSelf;
                    @endphp

                    <tr>
                        <td class="px-5 py-3">
                            {{ $member->user?->name ?? 'Unknown' }}
                            @if ($isOwner)
                                <span class="ml-1 text-xs text-slate-500">(owner)</span>
                            @elseif ($isSelf)
                                <span class="ml-1 text-xs text-slate-500">(you)</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-slate-600">{{ $member->user?->email }}</td>

                        <td class="px-5 py-3">
                            @if ($canManageRoles && ! $locked)
                                <form method="POST" action="{{ route('agency.team.role', $member) }}"
                                      class="flex items-center gap-2">
                                    @csrf
                                    @method('PUT')
                                    <select name="role" class="rounded-lg border border-slate-300 px-2 py-1 text-sm">
                                        @foreach ($roles as $role)
                                            <option value="{{ $role }}"
                                                @selected(($memberRoles[$member->user_id] ?? null) === $role)>
                                                {{ $role }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <button type="submit"
                                            class="rounded-lg border border-slate-300 px-2 py-1 text-xs hover:bg-slate-50">
                                        Save
                                    </button>
                                </form>
                            @else
                                {{ $memberRoles[$member->user_id] ?? '--' }}
                            @endif
                        </td>

                        <td class="px-5 py-3">{{ ucfirst($member->status->value) }}</td>

                        <td class="px-5 py-3 text-right">
                            @if ($canManage && ! $locked)
                                @if ($member->status->value === 'active')
                                    <form method="POST" action="{{ route('agency.team.suspend', $member) }}">
                                        @csrf
                                        {{-- Suspend rather than delete: the posts, approvals and
                                             audit entries this person created stay attributable,
                                             which a deleted membership would break. --}}
                                        <button type="submit"
                                                class="rounded-lg border border-slate-300 px-3 py-1 text-xs hover:bg-slate-50">
                                            Suspend access
                                        </button>
                                    </form>
                                @elseif ($member->status->value === 'suspended')
                                    <form method="POST" action="{{ route('agency.team.reinstate', $member) }}">
                                        @csrf
                                        <button type="submit"
                                                class="rounded-lg border border-slate-300 px-3 py-1 text-xs hover:bg-slate-50">
                                            Restore access
                                        </button>
                                    </form>
                                @endif
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($invitations->isNotEmpty())
        <h2 class="mt-8 mb-3 text-sm font-semibold">Pending invitations</h2>
        <ul class="divide-y divide-slate-200 overflow-hidden rounded-xl border border-slate-200 bg-white">
            @foreach ($invitations as $invitation)
                <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm">
                    <span>{{ $invitation->email }}</span>
                    <span class="flex items-center gap-3">
                        <span class="text-slate-600">
                            Expires {{ $invitation->expires_at?->diffForHumans() }}
                        </span>
                        @if ($canInvite)
                            {{-- An invitation sent to the wrong address stayed
                                 usable until it expired on its own. --}}
                            <form method="POST"
                                  action="{{ route('agency.team.invitation.revoke', $invitation) }}">
                                @csrf
                                <button type="submit"
                                        class="rounded-lg border border-slate-300 px-3 py-1 text-xs hover:bg-slate-50">
                                    Revoke
                                </button>
                            </form>
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($canInvite)
        <h2 class="mt-8 mb-3 text-sm font-semibold">Invite a team member</h2>
        <form method="POST" action="{{ route('agency.team.invite') }}"
              class="flex max-w-2xl flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-5">
            @csrf
            <div class="min-w-56 flex-1">
                <label for="email" class="block text-sm font-medium">Email</label>
                <x-agency.form.input id="email" name="email" type="email" required value="{{ old('email') }}" />
            </div>
            <div>
                <label for="role" class="block text-sm font-medium">Role</label>
                <select id="role" name="role"
                        class="mt-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @foreach ($roles as $role)
                        <option value="{{ $role }}">{{ $role }}</option>
                    @endforeach
                </select>
            </div>
            <x-agency.button>
                Send invitation
            </x-agency.button>
        </form>
    @endif
@endsection
