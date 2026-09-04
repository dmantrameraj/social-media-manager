<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Engagement\Services\SyncInboxService;
use App\Domain\Social\Contracts\SupportsInbox;
use App\Domain\Social\Enums\AccountStatus;
use App\Domain\Social\Exceptions\UnknownProvider;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Social\ProviderRegistry;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pulls conversations in from every account that can offer them.
 *
 * The inbox is only as useful as it is current: an agency that has to refresh
 * to discover a customer wrote an hour ago is running the browser tabs this
 * feature exists to replace.
 *
 * Only accounts whose provider implements SupportsInbox are polled -- most
 * networks expose only some of this and several expose none, which is why the
 * capability is an interface rather than an assumption.
 */
final class SyncInboxes extends Command
{
    protected $signature = 'inbox:sync
                            {--limit= : Most accounts to poll in one pass}
                            {--dry-run : List what would be polled and poll nothing}';

    protected $description = 'Fetch new comments and messages for connected accounts.';

    public function handle(
        TenantContext $context,
        ProviderRegistry $providers,
        SyncInboxService $sync,
    ): int {
        $limit = max(1, (int) ($this->option('limit')
            ?: config('engagement.sync_batch_size', 100)));

        /*
         | acrossTenants: a scheduled sweep has no request to resolve a tenant
         | from, and conversations arrive for every agency on the same clock.
         |
         | Only accounts that can actually publish. A disconnected or
         | needs-reconnect account has no working token, and polling it would
         | spend rate limit to be told so.
         */
        $accounts = SocialAccount::query()
            ->acrossTenants()
            ->where('status', AccountStatus::Active->value)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $polled = 0;
        $skipped = 0;
        $new = 0;

        foreach ($accounts as $account) {
            try {
                $provider = $providers->for($account->provider_key);
            } catch (UnknownProvider) {
                $skipped++;

                continue;
            }

            if (! $provider instanceof SupportsInbox) {
                $skipped++;

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line(sprintf('  #%d %s', $account->getKey(), $account->provider_key));
                $polled++;

                continue;
            }

            $tenant = Tenant::query()->find($account->tenant_id);

            if ($tenant === null) {
                continue;
            }

            try {
                /*
                 | Context re-established per account, exactly as the publishing
                 | job does, so every read and write below goes through the
                 | ordinary tenant scope and a bug here cannot reach another
                 | agency's conversations.
                 */
                $new += $context->run(
                    $tenant,
                    fn (): int => $sync->sync($account, $provider),
                );

                $polled++;
            } catch (Throwable $e) {
                /*
                 | One account's failure must not end the sweep. The next run
                 | picks it up, and a provider outage on one network should not
                 | stop an agency seeing what arrived on the others.
                 */
                $skipped++;

                Log::warning('Syncing an inbox failed.', [
                    'social_account_id' => $account->getKey(),
                    'provider' => $account->provider_key,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Polled {$polled}, skipped {$skipped}, {$new} new messages.");

        return self::SUCCESS;
    }
}
