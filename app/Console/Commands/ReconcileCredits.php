<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\AI\Services\ReconcileCreditsService;
use Illuminate\Console\Command;

/**
 * Brings every tenant's cached AI credit balance back in line with its ledger.
 *
 * docs/08-AI-ARCHITECTURE.md §5 says the cached balance "is reconciled on
 * schedule". `CreditLedger::reconcile()` could already answer whether one
 * tenant had drifted; nothing called it for more than one, and nothing acted
 * on what it found.
 */
final class ReconcileCredits extends Command
{
    protected $signature = 'ai:reconcile-credits';

    protected $description = "Correct any drift between a tenant's cached AI credit balance and its ledger.";

    public function handle(ReconcileCreditsService $service): int
    {
        $result = $service->execute();

        $this->info(sprintf(
            'Checked %d account(s), corrected %d.',
            $result['checked'],
            $result['corrected'],
        ));

        return self::SUCCESS;
    }
}
