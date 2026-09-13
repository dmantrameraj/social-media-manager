<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Publishing\Services\MaterialiseRecurringPostsService;
use Illuminate\Console\Command;

/**
 * Extends every recurring rule's window of concrete posts.
 *
 * Daily, because the window is measured in days and moves by one of them at a
 * time. Running it more often is harmless -- an occurrence already materialised
 * is found and skipped -- but it would be work that finds nothing.
 *
 * Without this command the rules table is furniture: a rule would be saved,
 * displayed, edited and would never produce a post. That failure has a long
 * history in this repository (PROJECT.md §7), so the command and the schedule
 * entry are part of the feature rather than a follow-up.
 */
final class MaterialiseRecurringPosts extends Command
{
    protected $signature = 'publishing:materialise-recurring';

    protected $description = 'Generate the next window of posts for every active recurring rule.';

    public function handle(MaterialiseRecurringPostsService $materialiser): int
    {
        $result = $materialiser->runAll();

        $this->info(sprintf(
            'Recurring: %d rule(s) considered, %d post(s) created, %d scheduled, %d skipped.',
            $result['rules'],
            $result['created'],
            $result['scheduled'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
