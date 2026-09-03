<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Domain\Billing\Entitlements\Exceptions\EntitlementExceeded;
use App\Domain\Customers\Models\Customer;
use App\Domain\Media\Exceptions\MediaRejected;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Services\SignedMediaUrl;
use App\Domain\Media\Services\StoreMediaService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class MediaController
{
    public function index(Request $request, SignedMediaUrl $urls): View
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
            /*
             | Signed, short-lived URLs for the previewable files only.
             | Minted here rather than in the template so the TTL stays a
             | single decision, and only for images: a video or PDF
             | thumbnail would stream the whole file to draw a tile.
             */
            'previews' => $media->filter(fn (Media $item): bool => $item->isImage() && $item->isUsable())
                // The THUMB variant: these are 320px tiles, and serving the
                // original into one streamed a multi-megabyte photo through PHP
                // for every image on the page. Falls back to the original if the
                // variant is not there yet.
                ->mapWithKeys(fn (Media $item) => [
                    $item->getKey() => $urls->forAgency($item, variant: 'thumb'),
                ]),

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
            /*
             | Asked for at upload, while the person who chose the file is still
             | looking at it. A later "fix your alt text" screen reliably
             | produces "photo" and "image1".
             */
            'alt_text' => ['nullable', 'string', 'max:1000'],
        ]);

        $brand = Customer::query()->findOrFail($validated['brand']);

        $request->user()->can('view', $brand) || abort(403);

        try {
            $service->execute(
                $brand,
                $request->user(),
                $request->file('file'),
                altText: $validated['alt_text'] ?? null,
            );
        } catch (MediaRejected|EntitlementExceeded $e) {
            // Only a quota failure earns the upgrade link. A rejected file --
            // wrong type, too large -- is not fixed by a bigger plan, and
            // offering one there would be misdirection.
            return back()
                ->with('error', $e->getMessage())
                ->with('upgrade_prompt', $e instanceof EntitlementExceeded);
        }

        return back()->with('status', 'File uploaded.');
    }

    /**
     * Edit the description of a file already uploaded.
     *
     * Separate from upload because the gap is real: every file that predates
     * this feature has none, and someone has to be able to go back and add it
     * without re-uploading the image.
     */
    public function update(Request $request, Media $media): RedirectResponse
    {
        $request->user()->can('media.upload') || abort(403);

        // The brand check, not just the tenant one: media is brand-scoped and a
        // user restricted to some brands must not edit another's.
        $request->user()->can('view', $media->customer) || abort(403);

        $validated = $request->validate([
            'alt_text' => ['nullable', 'string', 'max:1000'],
        ]);

        $alt = trim((string) ($validated['alt_text'] ?? ''));

        $media->forceFill(['alt_text' => $alt === '' ? null : $alt])->save();

        return back()->with('status', 'Description saved.');
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
