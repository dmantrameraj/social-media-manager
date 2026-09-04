<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Models\PostMetric;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;

/**
 * The figures behind a report, for whoever is asking.
 *
 * One place, so the dashboard, the CSV and a shared link cannot disagree about
 * the same month. Two screens reporting different numbers for one period is
 * the failure that makes an agency stop trusting the whole feature -- and it
 * happens whenever the query is written twice.
 */
final class BuildReportService
{
    /**
     * @param  list<int>  $customerIds
     * @return EloquentCollection<int, PostMetric>
     */
    public function metrics(array $customerIds, Carbon $from, Carbon $to): EloquentCollection
    {
        /*
         | latestPerTargetBetween, never every row: analytics are re-polled as
         | a post matures, so summing each collection counts the same post
         | several times over.
         */
        return PostMetric::query()
            ->latestPerTargetBetween($from, $to)
            ->whereIn('customer_id', $customerIds)
            ->with(['socialAccount', 'target.post', 'customer'])
            ->get();
    }

    /**
     * @param  EloquentCollection<int, PostMetric>  $metrics
     * @return array<string, int|null>
     */
    public function totals(EloquentCollection $metrics): array
    {
        $totals = [];

        foreach (PostMetric::summableColumns() as $column) {
            $reported = $metrics->filter(
                fn (PostMetric $m): bool => $m->{$column} !== null,
            );

            /*
             | Null when nothing reported it. A network that does not return
             | saves is not one returning zero, and a 0 in a client report
             | reads as "this failed" rather than "this was never measured".
             */
            $totals[$column] = $reported->isEmpty() ? null : (int) $reported->sum($column);
        }

        $totals['engagements'] = $metrics->sum(fn (PostMetric $m): int => $m->engagements());
        $totals['posts'] = $metrics->count();

        return $totals;
    }

    /**
     * One row per published post, for a spreadsheet.
     *
     * @param  EloquentCollection<int, PostMetric>  $metrics
     * @return list<array<string, string|int|float|null>>
     */
    public function rows(EloquentCollection $metrics): array
    {
        return $metrics
            ->sortByDesc(fn (PostMetric $m): int => $m->engagements())
            ->map(fn (PostMetric $m): array => [
                'date' => $m->collected_at->toDateString(),
                'brand' => $m->customer?->name,
                'account' => $m->socialAccount?->name,
                'network' => $m->provider_key,
                'post' => $m->target?->post?->title ?: 'Untitled post',
                'impressions' => $m->impressions,
                'reach' => $m->reach,
                'likes' => $m->likes,
                'comments' => $m->comments,
                'shares' => $m->shares,
                'saves' => $m->saves,
                'clicks' => $m->clicks,
                'video_views' => $m->video_views,
                'engagements' => $m->engagements(),
                /*
                 | Blank rather than 0 when impressions are unknown. A rate
                 | column showing 0% would tell a client nobody engaged, when
                 | the truth is that nothing was measured.
                 */
                'engagement_rate' => $m->engagementRate() === null
                    ? null
                    : round($m->engagementRate() * 100, 2),
            ])
            ->values()
            ->all();
    }

    /** @return list<string> */
    public function columns(): array
    {
        return [
            'date', 'brand', 'account', 'network', 'post',
            'impressions', 'reach', 'likes', 'comments', 'shares',
            'saves', 'clicks', 'video_views', 'engagements', 'engagement_rate',
        ];
    }
}
