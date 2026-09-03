@if (session('status'))
    <div role="status"
         class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
        {{ session('status') }}
    </div>
@endif

@if (session('error'))
    <div role="alert"
         class="mb-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
        {{ session('error') }}

        {{--
         | A plan limit is the one error the reader can actually resolve, so it
         | is the one that gets a way out. Gated on the permission rather than
         | shown to everyone: a Designer who hits the brand ceiling cannot
         | upgrade, and pointing them at a page they will be refused from is
         | worse than telling them nothing.
        --}}
        @if (session('upgrade_prompt') && auth()->user()?->can('billing.view'))
            <a href="{{ route('agency.billing') }}"
               class="mt-2 inline-block font-medium underline underline-offset-2 hover:no-underline">
                See your plan and upgrade
            </a>
        @endif
    </div>
@endif

@if ($errors->any())
    <div role="alert"
         class="mb-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
        <ul class="list-disc space-y-1 pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
