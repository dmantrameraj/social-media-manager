<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Models;

use App\Domain\Customers\Models\Customer;
use App\Domain\Publishing\Models\PostTarget;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\PostMetricFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What one published target achieved, at one moment.
 *
 * The normalised columns are ours and comparable across networks; `raw` keeps
 * whatever the provider actually returned. Normalisation is lossy and
 * irreversible, and a network that renames a field is discovered months later
 * -- by which time re-polling is impossible because the API has aged the data
 * out. Storage is cheap; a year of unrecoverable history is not.
 *
 * @property int $tenant_id
 * @property int $customer_id
 * @property int $post_target_id
 * @property int $social_account_id
 * @property string $provider_key
 * @property int|null $impressions
 * @property int|null $reach
 * @property int|null $likes
 * @property int|null $comments
 * @property int|null $shares
 * @property int|null $saves
 * @property int|null $clicks
 * @property int|null $video_views
 * @property array<string, mixed>|null $raw
 * @property Carbon $collected_at
 */
#[UseFactory(PostMetricFactory::class)]
class PostMetric extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * Nothing is fillable. Metrics are written by the collection service from
     * an adapter's response, never from request input -- a tenant able to
     * assert their own reach figure is a reporting product that reports
     * whatever it is told.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'impressions' => 'integer',
            'reach' => 'integer',
            'likes' => 'integer',
            'comments' => 'integer',
            'shares' => 'integer',
            'saves' => 'integer',
            'clicks' => 'integer',
            'video_views' => 'integer',
            'raw' => 'array',
            'collected_at' => 'datetime',
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(PostTarget::class, 'post_target_id');
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The metrics a dashboard adds up.
     *
     * Named so the list lives in one place: a report that sums a column the
     * dashboard does not, or the reverse, is how two screens come to disagree
     * about the same month.
     *
     * @return list<string>
     */
    public static function summableColumns(): array
    {
        return [
            'impressions', 'reach', 'likes', 'comments',
            'shares', 'saves', 'clicks', 'video_views',
        ];
    }

    /**
     * Interactions, which is what an engagement rate divides by impressions.
     *
     * Computed rather than stored: a stored derivative goes stale the moment
     * either side is corrected, and both sides are already on the row.
     */
    public function engagements(): int
    {
        return (int) $this->likes
            + (int) $this->comments
            + (int) $this->shares
            + (int) $this->saves
            + (int) $this->clicks;
    }

    /**
     * Engagements per impression, or null when there is nothing to divide by.
     *
     * Null rather than zero: a post whose impressions were never reported has
     * no rate, and showing 0% would read as "nobody engaged" rather than "we
     * do not know".
     */
    public function engagementRate(): ?float
    {
        if ($this->impressions === null || $this->impressions === 0) {
            return null;
        }

        return $this->engagements() / $this->impressions;
    }

    /**
     * The most recent figures per target, within a window.
     *
     * Analytics are polled repeatedly as a post matures, so a dashboard that
     * sums every row counts the same post several times over -- the single
     * most common way an analytics screen produces numbers nobody can
     * reconcile.
     *
     * The window is applied INSIDE the subquery as well as outside it. Taking
     * the newest row overall and then filtering by date would pick a row from
     * outside the window for a post still being polled, and silently report
     * last month's figures as this month's. That is worse than reporting
     * nothing, because it looks like an answer.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLatestPerTargetBetween(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query
            ->whereBetween('collected_at', [$from, $to])
            ->whereIn('id', function ($sub) use ($from, $to): void {
                $sub->selectRaw('MAX(id)')
                    ->from('post_metrics')
                    ->whereBetween('collected_at', [$from, $to])
                    ->groupBy('post_target_id');
            });
    }
}
