<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Domain\Analytics\Models\PostMetric;
use App\Domain\Customers\Models\Customer;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * What the work achieved.
 *
 * `analytics.view` has been in the permission catalogue since Step 5 and the
 * Analyst role has existed to hold it, governing nothing: there was no table,
 * no collection and no screen. An agency could publish for a client and had
 * nothing to show them at the end of the month.
 */
final class AnalyticsController extends Controller
{
    /** Longest window offered, so one request cannot scan a whole history. */
    private const MAX_DAYS = 365;

    public function index(Request $request): View
    {
        $request->user()->can('analytics.view') || abort(403);

        $validated = $request->validate([
            'brand' => ['nullable', 'integer'],
            'days' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_DAYS],
        ]);

        $brands = $this->visibleBrands($request);
        $days = (int) ($validated['days'] ?? 30);

        /*
         | Bounded to the brands this person can actually reach, then narrowed
         | to one if they picked it. A member assigned to one client must not
         | see another's numbers by supplying an id.
         */
        $selected = isset($validated['brand'])
            && $brands->contains(fn (Customer $b): bool => $b->getKey() === (int) $validated['brand'])
                ? (int) $validated['brand']
                : null;

        $to = Carbon::now();
        $from = $to->copy()->subDays($days);

        $metrics = $this->windowQuery($brands, $selected, $from, $to)
            ->with(['socialAccount', 'target.post', 'customer'])
            ->get();

        return view('agency.analytics.index', [
            'title' => 'Analytics',
            'brands' => $brands,
            'selectedBrand' => $selected,
            'days' => $days,
            'from' => $from,
            'to' => $to,

            'totals' => $this->totals($metrics),

            /*
             | Sorted by engagements rather than impressions: an agency
             | reporting to a client is asked what worked, and reach without
             | interaction answers a different question.
             */
            'top' => $metrics
                ->sortByDesc(fn (PostMetric $m): int => $m->engagements())
                ->take(10)
                ->values(),

            'byAccount' => $metrics
                ->groupBy('social_account_id')
                ->map(fn ($rows) => [
                    'account' => $rows->first()->socialAccount,
                    'posts' => $rows->count(),
                    'totals' => $this->totals($rows),
                ])
                ->values(),
        ]);
    }

    /**
     * The rows a dashboard may add up.
     *
     * latestPerTargetBetween, never every row: analytics are re-polled as a
     * post matures, and summing each collection counts the same post several
     * times over. That is the usual reason an analytics screen produces
     * figures nobody can reconcile.
     *
     * @param  EloquentCollection<int, Customer>  $brands
     * @return Builder<PostMetric>
     */
    private function windowQuery(
        EloquentCollection $brands,
        ?int $selected,
        Carbon $from,
        Carbon $to,
    ): Builder {
        return PostMetric::query()
            ->latestPerTargetBetween($from, $to)
            ->whereIn(
                'customer_id',
                $selected !== null ? [$selected] : $brands->modelKeys(),
            );
    }

    /**
     * @param  EloquentCollection<int, PostMetric>  $metrics
     * @return array<string, int|null>
     */
    private function totals(EloquentCollection $metrics): array
    {
        $totals = [];

        foreach (PostMetric::summableColumns() as $column) {
            $reported = $metrics->filter(
                fn (PostMetric $m): bool => $m->{$column} !== null,
            );

            /*
             | Null when nothing reported it at all. A network that does not
             | return saves is not the same as one returning zero, and a 0 in
             | a client report reads as "this failed" rather than "this was
             | never measured".
             */
            $totals[$column] = $reported->isEmpty()
                ? null
                : (int) $reported->sum($column);
        }

        $totals['engagements'] = $metrics->sum(
            fn (PostMetric $m): int => $m->engagements(),
        );

        $totals['posts'] = $metrics->count();

        return $totals;
    }

    /**
     * Brands this person may see, which is not always every brand.
     *
     * @return EloquentCollection<int, Customer>
     */
    private function visibleBrands(Request $request): EloquentCollection
    {
        $query = Customer::query()->orderBy('name');

        if (! $request->user()->can('customers.view_all')) {
            $query->whereIn('id', $request->user()->assignedCustomerIds());
        }

        return $query->get();
    }
}
