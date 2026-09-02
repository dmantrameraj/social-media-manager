<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Domain\Billing\Entitlements\Exceptions\EntitlementExceeded;
use App\Domain\Customers\Models\Customer;
use App\Domain\Media\Exceptions\MediaRejected;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Services\StoreMediaService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class MediaController
{
    public function index(Request $request): View
    {
        $request->user()->can('media.view') || abort(403);

        $brands = $this->visibleBrands($request);

        $selected = $request->integer('brand') ?: $brands->first()?->getKey();

        $media = Media::query()
            ->when($selected, fn ($q) => $q->where('customer_id', $selected))
            // Only brands the user may see, so the filter cannot be used to
            // enumerate media from an unassigned brand.
            ->whereIn('customer_id', $brands->pluck('id'))
            ->latest('id')
            ->paginate(24);

        return view('agency.media.index', [
            'title' => 'Media',
            'brands' => $brands,
            'selected' => $selected,
            'media' => $media,
            'canUpload' => $request->user()->can('media.upload'),
        ]);
    }

    public function store(Request $request, StoreMediaService $service): RedirectResponse
    {
        $request->user()->can('media.upload') || abort(403);

        $validated = $request->validate([
            'brand' => ['required', 'integer'],
            // Size and type are enforced again inside the service against
            // sniffed content -- this is only a fast first pass.
            'file' => ['required', 'file'],
        ]);

        $brand = Customer::query()->findOrFail($validated['brand']);

        $request->user()->can('view', $brand) || abort(403);

        try {
            $service->execute($brand, $request->user(), $request->file('file'));
        } catch (MediaRejected|EntitlementExceeded $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'File uploaded.');
    }

    /**
     * Brands this user may see, honouring the assignment dimension.
     *
     * @return Collection<int, Customer>
     */
    private function visibleBrands(Request $request)
    {
        $query = Customer::query()->active()->orderBy('name');

        if (! $request->user()->can('customers.view_all')) {
            $query->whereIn('id', $request->user()->assignedCustomerIds());
        }

        return $query->get();
    }
}
