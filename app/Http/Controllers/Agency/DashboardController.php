<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Domain\AI\Credits\CreditLedger;
use App\Domain\Customers\Models\Customer;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Models\Post;
use App\Support\TenantContext;
use Illuminate\Contracts\View\View;

final class DashboardController
{
    public function __invoke(TenantContext $context, CreditLedger $ledger): View
    {
        $tenant = $context->get();

        /*
         | Every query below is tenant-scoped automatically by the global
         | scope. Counts rather than collections: a dashboard that loads every
         | post to count them is the first thing to fall over at scale.
         */
        return view('agency.dashboard', [
            'title' => 'Dashboard',
            'brandCount' => Customer::query()->active()->count(),
            'draftCount' => Post::query()->where('status', PostStatus::Draft->value)->count(),
            'scheduledCount' => Post::query()->where('status', PostStatus::Scheduled->value)->count(),
            'needsApproval' => Post::query()->whereIn('status', [
                PostStatus::InternalReview->value,
                PostStatus::ClientReview->value,
            ])->count(),
            'upcoming' => Post::query()
                ->whereNotNull('scheduled_at')
                ->where('scheduled_at', '>=', now())
                ->orderBy('scheduled_at')
                ->limit(5)
                ->get(),
            'credits' => $this->credits($ledger, $tenant),
        ]);
    }

    /**
     * Credit balance is best-effort: a tenant provisioned before the ledger
     * existed should still see a dashboard rather than a 500.
     */
    private function credits(CreditLedger $ledger, $tenant): ?int
    {
        try {
            return $ledger->accountFor($tenant)->available();
        } catch (\Throwable) {
            return null;
        }
    }
}
