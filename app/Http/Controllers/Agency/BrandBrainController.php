<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Domain\AI\Models\BrandBrain;
use App\Domain\Audit\AuditLogger;
use App\Domain\Customers\Models\Customer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agency\UpdateBrandBrainRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The brand profile every AI feature is grounded in.
 *
 * Until this existed, all twelve AI features were built and unusable: the only
 * way to fill a Brand Brain was a direct database write, so an agency could
 * generate captions but not tell the system anything about the client it was
 * generating them for.
 *
 * Gated on ai.manage_brand_brain rather than customers.update. Editing this
 * changes what the AI says on a client's behalf across every future post,
 * which is a different kind of authority from editing a brand's timezone.
 */
final class BrandBrainController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function edit(Request $request, Customer $brand): View
    {
        $request->user()->can('ai.manage_brand_brain') || abort(403);
        $request->user()->can('view', $brand) || abort(403);

        // Looked up, never created: opening the page must not write a row. An
        // empty Brand Brain that exists is indistinguishable from a configured
        // one when reading counts, and it changes what completeness means.
        $brain = BrandBrain::query()->where('customer_id', $brand->getKey())->first()
            ?? new BrandBrain(['primary_language' => 'en']);

        return view('agency.brands.brain', [
            'title' => $brand->name.' — brand brain',
            'brand' => $brand,
            'brain' => $brain,
            'completeness' => $brain->exists ? $brain->completeness() : 0,
            'listFields' => UpdateBrandBrainRequest::LIST_FIELDS,
        ]);
    }

    public function update(UpdateBrandBrainRequest $request, Customer $brand): RedirectResponse
    {
        $request->user()->can('ai.manage_brand_brain') || abort(403);
        $request->user()->can('view', $brand) || abort(403);

        /*
         | Looked up and instantiated separately rather than with firstOrNew:
         | that helper mass-assigns its search attributes, and customer_id and
         | tenant_id are guarded on purpose -- a tenant key that can be filled
         | from a payload is how a request reaches another tenant's row. Both
         | are set explicitly below, from the resolved brand.
         */
        $brain = BrandBrain::query()->where('customer_id', $brand->getKey())->first()
            ?? new BrandBrain;

        $before = $brain->exists ? $brain->completeness() : 0;

        $brain->fill($request->payload());
        $brain->customer_id = $brand->getKey();
        $brain->tenant_id = $brand->tenant_id;
        $brain->save();

        /*
         | Audited because this is the text the AI speaks in the client's name.
         | Values are not recorded -- a brand profile is long free text and the
         | audit log is not the place to duplicate it -- but who changed it and
         | how complete it became is exactly what a later "why did it write
         | that?" needs.
         */
        $this->audit->log(
            'ai.brand_brain_updated',
            $brain,
            oldValues: ['completeness' => $before],
            newValues: ['completeness' => $brain->completeness()],
            actor: $request->user(),
        );

        return back()->with('status', 'Brand brain saved.');
    }
}
