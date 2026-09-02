@php
    $context = app(\App\Support\TenantContext::class);
    $tenant = $context->hasTenant() ? $context->get() : null;
@endphp

{{--
  Lifecycle state is surfaced at the top of every screen rather than only on
  the billing page. A trial that ends silently is how an agency loses a day of
  scheduled posts without knowing why.
--}}
@if ($tenant?->status === \App\Domain\Tenancy\Enums\TenantStatus::Trialing && $tenant->trial_ends_at)
    <div class="border-b border-amber-200 bg-amber-50 px-6 py-2 text-sm text-amber-900">
        Trial ends {{ $tenant->trial_ends_at->diffForHumans() }}.
        @can('billing.manage')
            <a href="{{ route('agency.billing') }}" class="font-medium underline">Choose a plan</a>
        @endcan
    </div>
@elseif ($tenant?->status === \App\Domain\Tenancy\Enums\TenantStatus::Grace)
    <div class="border-b border-rose-200 bg-rose-50 px-6 py-2 text-sm text-rose-900">
        Your subscription has lapsed. Publishing continues for now, but access will be
        restricted soon.
        @can('billing.manage')
            <a href="{{ route('agency.billing') }}" class="font-medium underline">Renew</a>
        @endcan
    </div>
@endif
