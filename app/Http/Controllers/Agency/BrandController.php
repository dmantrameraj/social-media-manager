<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Billing\Entitlements\Exceptions\EntitlementExceeded;
use App\Domain\Customers\Models\Customer;
use App\Domain\Customers\Services\CreateCustomerService;
use App\Domain\Customers\Services\UpdateCustomerService;
use App\Http\Requests\Agency\StoreBrandRequest;
use App\Http\Requests\Agency\UpdateBrandRequest;
use App\Support\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class BrandController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementResolver $entitlements,
    ) {}

    public function index(Request $request): View
    {
        $request->user()->can('customers.view') || abort(403);

        $query = Customer::query()->orderBy('name');

        /*
         | Two-dimensional access: holding customers.view is not enough --
         | without customers.view_all a user sees only brands they are
         | assigned to. Filtering in the query as well as in the policy means
         | the list never shows a row that would 403 when clicked.
         */
        if (! $request->user()->can('customers.view_all')) {
            $query->whereIn('id', $request->user()->assignedCustomerIds());
        }

        return view('agency.brands.index', [
            'title' => 'Brands',
            'brands' => $query->paginate(20),
            'canCreate' => $request->user()->can('customers.create')
                && $this->entitlements->allows($this->context->get(), 'brands.max'),
            'limit' => $this->entitlements->value($this->context->get(), 'brands.max'),
            'used' => $this->entitlements->currentUsage($this->context->get(), 'brands.max'),
        ]);
    }

    public function create(Request $request): View
    {
        $request->user()->can('create', Customer::class) || abort(403);

        return view('agency.brands.create', ['title' => 'New brand']);
    }

    public function store(StoreBrandRequest $request, CreateCustomerService $service): RedirectResponse
    {
        $request->user()->can('create', Customer::class) || abort(403);

        try {
            $brand = $service->execute($this->context->get(), $request->user(), $request->validated());
        } catch (EntitlementExceeded $e) {
            // Rendered as a clear message with an upgrade path, never a 500.
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('agency.brands.show', $brand)
            ->with('status', "{$brand->name} was created.");
    }

    public function show(Request $request, Customer $brand): View
    {
        $request->user()->can('view', $brand) || abort(403);

        return view('agency.brands.show', [
            'title' => $brand->name,
            'brand' => $brand,
            'mediaCount' => $brand->media()->count(),
            'postCount' => $brand->posts()->count(),
        ]);
    }

    public function update(UpdateBrandRequest $request, Customer $brand, UpdateCustomerService $service): RedirectResponse
    {
        $request->user()->can('update', $brand) || abort(403);

        $service->execute($brand, $request->validated());

        return redirect()
            ->route('agency.brands.show', $brand)
            ->with('status', 'Brand updated.');
    }

    public function archive(Request $request, Customer $brand, UpdateCustomerService $service): RedirectResponse
    {
        $request->user()->can('archive', $brand) || abort(403);

        $service->archive($brand);

        return redirect()
            ->route('agency.brands.index')
            ->with('status', "{$brand->name} was archived.");
    }

    public function unarchive(Request $request, Customer $brand, UpdateCustomerService $service): RedirectResponse
    {
        $request->user()->can('unarchive', $brand) || abort(403);

        try {
            $service->unarchive($brand);
        } catch (EntitlementExceeded $e) {
            // Restoring consumes a slot again, so this can legitimately fail
            // on a downgraded plan.
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('agency.brands.show', $brand)
            ->with('status', "{$brand->name} was restored.");
    }
}
