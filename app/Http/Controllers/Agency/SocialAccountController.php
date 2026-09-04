<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Domain\Audit\AuditLogger;
use App\Domain\Billing\Entitlements\Exceptions\EntitlementExceeded;
use App\Domain\Customers\Models\Customer;
use App\Domain\Social\DTO\DiscoveredAccount;
use App\Domain\Social\Enums\AccountStatus;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Social\Models\SocialConnection;
use App\Domain\Social\ProviderRegistry;
use App\Domain\Social\Services\StoreSocialConnectionService;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The connected destinations, and choosing which of them to use.
 *
 * A grant is not a destination. One Meta connection commonly yields several
 * Pages plus the Instagram accounts linked to them, and attaching all of them
 * to a brand automatically is how somebody posts a client's content to the
 * wrong Page. So the callback lands here and a person chooses.
 */
final class SocialAccountController extends Controller
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly StoreSocialConnectionService $connections,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $request->user()->can('social_accounts.view') || abort(403);

        return view('agency.social.index', [
            'title' => 'Connected accounts',
            'accounts' => SocialAccount::query()
                ->with('customer')
                ->orderBy('name')
                ->get(),
            'brands' => Customer::query()->orderBy('name')->get(),
            'providers' => $this->providers->enabled(),
            'canConnect' => $request->user()->can('social_accounts.connect'),
            'canDisconnect' => $request->user()->can('social_accounts.disconnect'),
        ]);
    }

    /**
     * Which destinations does this grant offer?
     */
    public function choose(Request $request, SocialConnection $connection): View|RedirectResponse
    {
        $request->user()->can('social_accounts.connect') || abort(403);

        try {
            $discovered = $this->providers
                ->for($connection->provider_key)
                ->discoverAccounts($connection);
        } catch (Throwable $e) {
            /*
             | The grant is stored and valid; only discovery failed. Sending
             | them back to the list with the connection intact is better than
             | losing it -- they can retry without authorising again.
             */
            Log::error('Could not discover accounts for a connection.', [
                'social_connection_id' => $connection->getKey(),
                'exception' => $e->getMessage(),
            ]);

            return redirect()
                ->route('agency.social.index')
                ->with('error', 'Connected, but we could not list the accounts. Try again shortly.');
        }

        return view('agency.social.choose', [
            'title' => 'Choose accounts',
            'connection' => $connection,
            'discovered' => $discovered,
            'brands' => Customer::query()->orderBy('name')->get(),
            // The brand the connection was started for, preselected.
            'selectedBrand' => $connection->customer_id,
        ]);
    }

    /**
     * Attach the chosen destinations to a brand.
     */
    public function store(Request $request, SocialConnection $connection): RedirectResponse
    {
        $request->user()->can('social_accounts.connect') || abort(403);

        $validated = $request->validate([
            'customer' => ['required', 'integer'],
            'accounts' => ['required', 'array', 'min:1'],
            'accounts.*' => ['string'],
        ]);

        $brand = Customer::query()->find($validated['customer']);

        abort_if($brand === null, 404);

        $request->user()->can('view', $brand) || abort(403);

        /*
         | Re-discovered rather than trusted from the form. The submitted list
         | is only a set of external ids; taking names, tokens or types from it
         | would let a crafted payload write whatever it liked into a row that
         | publishing later treats as authoritative.
         */
        try {
            $available = $this->providers
                ->for($connection->provider_key)
                ->discoverAccounts($connection);
        } catch (Throwable $e) {
            Log::error('Could not re-discover accounts while saving.', [
                'social_connection_id' => $connection->getKey(),
                'exception' => $e->getMessage(),
            ]);

            return back()->with('error', 'We could not confirm those accounts. Try again shortly.');
        }

        $chosen = array_values(array_filter(
            $available->all(),
            fn (DiscoveredAccount $account): bool => in_array(
                $account->externalId,
                $validated['accounts'],
                true,
            ),
        ));

        if ($chosen === []) {
            return back()->with('error', 'Those accounts are no longer available on this connection.');
        }

        try {
            $stored = $this->connections->storeAccounts($connection, $brand->getKey(), $chosen);
        } catch (EntitlementExceeded $e) {
            // A clear message with a way out, never a 500. upgrade_prompt is
            // what the flash partial reads to offer the billing link.
            return back()
                ->with('error', $e->getMessage())
                ->with('upgrade_prompt', true);
        }

        return redirect()
            ->route('agency.social.index')
            ->with('status', "Connected {$stored} ".str('account')->plural($stored).' to '.$brand->name.'.');
    }

    /**
     * Stop using a destination.
     */
    public function destroy(Request $request, SocialAccount $account): RedirectResponse
    {
        $request->user()->can('social_accounts.disconnect') || abort(403);

        /*
         | Marked disconnected, NOT deleted. post_targets.social_account_id
         | cascades on delete, so removing the row would take every post ever
         | published to that account with it -- the history of what went where,
         | which is the thing an agency is most likely to be asked about later.
         |
         | AccountStatus::Disconnected already means exactly this: canPublish()
         | is false, and countsTowardLimit() is false so the seat is freed.
         */
        $account->forceFill([
            'status' => AccountStatus::Disconnected->value,
            /*
             | And the token goes, which the migration asked for in as many
             | words: "Disconnect sets status and nulls the tokens instead."
             | A disconnected account has no use for a live page token, and
             | keeping credentials at rest past the moment they are needed is
             | how a database read later becomes a publishing incident.
             */
            'page_access_token' => null,
            'token_expires_at' => null,
        ])->save();

        /*
         | The GRANT is left alone. Several destinations usually share one
         | connection, and revoking it here would silently disconnect the
         | others -- including ones belonging to a different brand.
         */
        $this->audit->log(
            'social.account_disconnected',
            $account,
            newValues: ['provider' => $account->provider_key],
            actor: $request->user(),
            tenantId: $account->tenant_id,
        );

        return back()->with('status', 'That account was disconnected. Its published history is kept.');
    }
}
