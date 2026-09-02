@php
    /*
     | Rendered on every agency page while a Super Admin is acting as someone
     | else. Three jobs, in this order of importance:
     |
     |   1. make it impossible to forget you are not yourself
     |   2. show the clock, because the session ends on a timer
     |   3. give a one-click exit that is always reachable
     |
     | Read straight from the session rather than passed in by each controller:
     | a banner that depends on every controller remembering to supply it is a
     | banner that will be missing from exactly the page where it matters.
     */
    $impersonation = app(\App\Domain\Platform\Services\ImpersonationService::class);
    // request()->session(), not session(): the helper with no arguments
    // returns the SessionManager, which is not a Session instance.
    $session = $impersonation->current(request()->session());
@endphp

@if ($session !== null)
    @php
        $remaining = max(0, (int) now()->diffInMinutes($session->expiresAt(), false));
    @endphp

    <div role="alert"
         class="flex flex-wrap items-center justify-between gap-3 bg-amber-500 px-6 py-3 text-sm text-amber-950">
        <div class="min-w-0">
            <strong class="font-semibold">Impersonating {{ auth()->user()?->name }}</strong>
            <span class="opacity-90">
                as {{ $session->superAdmin?->name ?? 'a platform administrator' }} ·
                {{ $session->elapsedMinutes() }} min elapsed ·
                {{ $remaining }} min remaining
            </span>
            <span class="block text-xs opacity-80">
                Everything you do is recorded against both accounts.
            </span>
        </div>

        <form method="POST" action="{{ route('admin.impersonation.stop') }}">
            @csrf
            @method('DELETE')
            <button type="submit"
                    class="rounded-lg bg-amber-950 px-4 py-2 text-sm font-medium text-white hover:bg-black">
                Exit impersonation
            </button>
        </form>
    </div>
@endif
