<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Domain\AI\AiFeatureRegistry;
use App\Domain\AI\Contracts\AiFeatureInterface;
use App\Domain\AI\Credits\CreditLedger;
use App\Domain\AI\Credits\Exceptions\InsufficientCredits;
use App\Domain\AI\Exceptions\AiFeatureUnavailable;
use App\Domain\AI\Exceptions\AiProviderException;
use App\Domain\AI\Services\GenerateContentService;
use App\Domain\Billing\Entitlements\Exceptions\EntitlementExceeded;
use App\Domain\Customers\Models\Customer;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The AI studio: pick a feature, give it context, get a result.
 *
 * Twelve features, the credit ledger, reservations, the Brand Brain context
 * builder and the whole provider abstraction were built and tested, and no
 * screen reached any of them. `ai.use` was in the permission catalogue
 * governing nothing, and an agency's monthly credits could only be spent by a
 * test naming a feature class directly.
 *
 * The form is built from each feature's own inputFields(), not from a list
 * kept here -- a second list drifts, and the symptom is a field the model
 * silently never receives.
 */
final class AiController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AiFeatureRegistry $features,
        private readonly CreditLedger $ledger,
        private readonly GenerateContentService $generator,
    ) {}

    public function index(Request $request): View
    {
        $request->user()->can('ai.use') || abort(403);

        /*
         | withCosts() is cost-ordered and exists, in its own words, so "a
         | feature is never offered without a price" -- cheapest first is also
         | the order somebody browsing wants.
         */
        $costs = $this->features->withCosts();

        return view('agency.ai.index', [
            'title' => 'AI studio',
            'features' => array_map(
                fn (string $key): AiFeatureInterface => $this->features->get($key),
                array_keys($costs),
            ),
            'costs' => $costs,
            'brands' => Customer::query()->orderBy('name')->get(),
            'account' => $this->ledger->accountFor($this->context->get()),
        ]);
    }

    /**
     * One feature's form.
     */
    public function show(Request $request, string $feature): View|RedirectResponse
    {
        $request->user()->can('ai.use') || abort(403);

        if (! $this->features->has($feature)) {
            // Never resolved from the key directly -- a key from a URL that
            // instantiates a class is how a picker becomes an exploit.
            return redirect()
                ->route('agency.ai.index')
                ->with('error', 'That tool is not available.');
        }

        $brands = Customer::query()->orderBy('name')->get();

        return view('agency.ai.show', [
            'title' => $this->features->get($feature)->label(),
            'feature' => $this->features->get($feature),
            'cost' => (int) config("ai.costs.{$feature}", 1),
            'brands' => $brands,
            'account' => $this->ledger->accountFor($this->context->get()),
            'result' => null,
        ]);
    }

    /**
     * Run it.
     */
    public function generate(Request $request, string $feature): View|RedirectResponse
    {
        $request->user()->can('ai.use') || abort(403);

        if (! $this->features->has($feature)) {
            return redirect()
                ->route('agency.ai.index')
                ->with('error', 'That tool is not available.');
        }

        $definition = $this->features->get($feature);

        $validated = $request->validate(
            $this->rulesFor($definition) + ['customer' => ['required', 'integer']],
        );

        $brand = Customer::query()->find($validated['customer']);

        // The tenant scope hides another agency's brand, so a foreign id and a
        // deleted one are indistinguishable from outside.
        abort_if($brand === null, 404);

        $request->user()->can('view', $brand) || abort(403);

        $input = collect($definition->inputFields())
            ->mapWithKeys(fn (array $field): array => [
                $field['name'] => $validated[$field['name']] ?? null,
            ])
            ->filter(fn (mixed $value): bool => $value !== null && $value !== '')
            ->all();

        try {
            $result = $this->generator->execute(
                $definition,
                $brand,
                $request->user(),
                $input,
                /*
                 | The form's own token, so a double submit -- a refresh, an
                 | impatient second click -- reuses the first generation
                 | instead of charging the tenant twice for the same request.
                 */
                $request->string('_token')->toString().':'.$feature,
            );
        } catch (InsufficientCredits $e) {
            return back()->withInput()
                ->with('error', $e->getMessage())
                ->with('upgrade_prompt', true);
        } catch (EntitlementExceeded $e) {
            return back()->withInput()
                ->with('error', $e->getMessage())
                ->with('upgrade_prompt', true);
        } catch (AiFeatureUnavailable $e) {
            /*
             | A feature refusing because the Brand Brain is too thin is
             | actionable, and saying which brand to fill in is the whole
             | value of the message.
             */
            return back()->withInput()->with('error', $e->getMessage());
        } catch (AiProviderException $e) {
            /*
             | The reservation is already released by the service. The raw
             | provider message is not shown -- it can carry request details --
             | and nothing about the failure is worth a 500 page.
             */
            Log::error('An AI generation failed.', [
                'feature' => $feature,
                'tenant_id' => $this->context->get()->getKey(),
                'exception' => $e->getMessage(),
            ]);

            return back()->withInput()
                ->with('error', 'That did not come back. No credits were charged; please try again.');
        }

        return view('agency.ai.show', [
            'title' => $definition->label(),
            'feature' => $definition,
            'cost' => (int) config("ai.costs.{$feature}", 1),
            'brands' => Customer::query()->orderBy('name')->get(),
            'account' => $this->ledger->accountFor($this->context->get()->fresh()),
            'result' => $result,
            'selectedBrand' => $brand->getKey(),
        ]);
    }

    /**
     * Validation built from the feature's own declaration.
     *
     * @return array<string, list<string>>
     */
    private function rulesFor(AiFeatureInterface $definition): array
    {
        $rules = [];

        foreach ($definition->inputFields() as $field) {
            $set = [($field['required'] ?? false) ? 'required' : 'nullable'];

            $set[] = match ($field['type']) {
                'number' => 'integer',
                'date' => 'date',
                default => 'string',
            };

            if ($field['type'] === 'number') {
                // Bounds come from the field, so a feature that means 1-90 days
                // cannot be handed 90,000 and spend a tenant's month of credits
                // on one absurd request.
                $set[] = 'min:'.($field['min'] ?? 1);
                $set[] = 'max:'.($field['max'] ?? 1000);
            }

            if ($field['type'] === 'textarea') {
                $set[] = 'max:20000';
            }

            if ($field['type'] === 'text') {
                $set[] = 'max:500';
            }

            $rules[$field['name']] = $set;
        }

        return $rules;
    }
}
