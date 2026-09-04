<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Analytics\Models\ReportShare;
use App\Domain\Customers\Enums\PortalRole;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\CustomerPortalUser;
use App\Domain\Notifications\MonthlyReportNotification;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends each client last month's report, once a month.
 *
 * The roadmap's last Phase 5 item. It exists because the report an agency
 * never remembers to send is the report the client never sees -- and "what did
 * we get for our money" is the question the whole product is answering.
 *
 * Built on the share link rather than beside it: one link type, one expiry
 * rule, one revocation path. A second mechanism for "a report a client can
 * open" would be a second thing to get wrong.
 */
final class SendMonthlyReports extends Command
{
    protected $signature = 'reports:send-monthly
                            {--dry-run : List who would be written to and send nothing}';

    protected $description = 'Send each client a link to last month\'s report.';

    public function handle(TenantContext $context): int
    {
        /*
         | LAST month, always. Run on the 1st, "this month" is a few hours old
         | and the report would be empty; naming the period explicitly also
         | means a retried or late run sends the same thing rather than a
         | different one.
         */
        $from = now()->subMonthNoOverflow()->startOfMonth();
        $to = $from->copy()->endOfMonth();
        $period = $from->format('F Y');

        $sent = 0;
        $skipped = 0;

        /*
         | acrossTenants: a scheduled sweep has no request to resolve a tenant
         | from, and every agency's month ends on the same calendar.
         */
        $customers = Customer::query()
            ->acrossTenants()
            ->active()
            ->orderBy('id')
            ->get();

        foreach ($customers as $customer) {
            $tenant = Tenant::query()->find($customer->tenant_id);

            /*
             | A suspended agency does not send client-facing mail. Their
             | client relationship is not ours to maintain while they are cut
             | off, and a report arriving from a product the agency can no
             | longer log into invites a confusing conversation.
             */
            if ($tenant === null || ! $tenant->permitsPublishing()) {
                $skipped++;

                continue;
            }

            try {
                $result = $context->run($tenant, function () use ($customer, $from, $to, $period): bool {
                    $recipients = CustomerPortalUser::query()
                        ->whereHas('customers', fn ($q) => $q
                            ->where('customers.id', $customer->getKey())
                            ->whereIn('customer_portal_user_customer.role', [
                                PortalRole::Approver->value,
                                PortalRole::Viewer->value,
                            ]))
                        ->get();

                    // No audience, no link. Minting a share nobody was sent is
                    // an unauthenticated view of a client's data created for
                    // no reason.
                    if ($recipients->isEmpty()) {
                        return false;
                    }

                    if ($this->option('dry-run')) {
                        $this->line(sprintf(
                            '  %s -> %d recipient(s)',
                            $customer->name,
                            $recipients->count(),
                        ));

                        return true;
                    }

                    ['token' => $token, 'hash' => $hash] = ReportShare::newToken();

                    $share = new ReportShare;

                    $share->forceFill([
                        'tenant_id' => $customer->tenant_id,
                        'customer_id' => $customer->getKey(),
                        'token_hash' => $hash,
                        'window_from' => $from,
                        'window_to' => $to,
                        /*
                         | Long enough that a client returning to the mail in
                         | six weeks still finds it working, short enough that
                         | an old inbox is not a standing window into a
                         | business's performance.
                         */
                        'expires_at' => now()->addDays(
                            (int) config('analytics.monthly_report_expiry_days', 60),
                        ),
                    ])->save();

                    Notification::send($recipients, new MonthlyReportNotification(
                        route('reports.shared', $token),
                        $customer->name,
                        $period,
                        $share,
                    ));

                    return true;
                });

                $result ? $sent++ : $skipped++;
            } catch (Throwable $e) {
                /*
                 | One brand's failure must not stop the rest. A month-end run
                 | that dies on the third of forty clients is worse than one
                 | that reports what it missed.
                 */
                $skipped++;

                Log::error('Sending a monthly report failed.', [
                    'customer_id' => $customer->getKey(),
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Sent {$sent}, skipped {$skipped}, for {$period}.");

        return self::SUCCESS;
    }
}
