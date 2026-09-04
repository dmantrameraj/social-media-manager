<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Models\PostMetric;
use App\Domain\Publishing\Models\PostTarget;
use Illuminate\Support\Carbon;

/**
 * Turns one provider response into a normalised row, keeping the original.
 *
 * The normalisation is deliberately dumb: it reads canonical keys and nothing
 * else. Every network names its metrics differently -- and what each name
 * actually counts differs too -- so translating a provider's vocabulary into
 * ours is the ADAPTER's job, done against that provider's live documentation.
 *
 * Putting a mapping table here instead would mean guessing, in the one place
 * where a wrong guess is invisible: a number that is merely wrong still looks
 * like a number, and an agency would report it to their client.
 */
final class RecordPostMetricsService
{
    /**
     * @param  array<string, mixed>  $metrics  normalised keys from the adapter
     * @param  array<string, mixed>|null  $raw  exactly what the provider returned
     */
    public function record(
        PostTarget $target,
        array $metrics,
        ?array $raw = null,
        ?Carbon $collectedAt = null,
    ): PostMetric {
        /*
         | Truncated to the minute. Collection runs on a schedule, and a
         | timestamp carrying seconds would make the unique key useless -- two
         | runs a second apart would both insert, and the dashboard would count
         | the post twice.
         */
        $collectedAt = ($collectedAt ?? now())->startOfMinute();

        $row = PostMetric::query()
            ->where('post_target_id', $target->getKey())
            ->where('collected_at', $collectedAt)
            ->first() ?? new PostMetric;

        $values = [
            'tenant_id' => $target->tenant_id,
            'customer_id' => $target->post->customer_id,
            'post_target_id' => $target->getKey(),
            'social_account_id' => $target->social_account_id,
            'provider_key' => $target->provider_key,
            'raw' => $raw,
            'collected_at' => $collectedAt,
        ];

        foreach (PostMetric::summableColumns() as $column) {
            /*
             | Absent stays null. A network that does not report saves is not
             | the same as one reporting zero, and coercing the first into the
             | second makes every average that touches it quietly wrong.
             */
            $values[$column] = array_key_exists($column, $metrics) && $metrics[$column] !== null
                ? max(0, (int) $metrics[$column])
                : null;
        }

        $row->forceFill($values)->save();

        return $row;
    }
}
