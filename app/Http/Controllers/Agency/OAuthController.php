<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Domain\Customers\Models\Customer;
use App\Domain\Social\Exceptions\OAuthStateInvalid;
use App\Domain\Social\OAuth\OAuthStateService;
use App\Domain\Social\ProviderRegistry;
use App\Domain\Social\Services\ResolveAppCredentialService;
use App\Domain\Social\Services\StoreSocialConnectionService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Connecting a social account, both legs of it.
 *
 * OAuthStateService was written with all four of its properties -- single use,
 * bound to a tenant and user, expiring, unguessable -- and had no callers.
 * There were no routes and no controller, so an agency could not connect an
 * account at all, which made every publishable destination in the product
 * reachable only by seeding one directly into the database.
 *
 * Nothing here knows a provider's endpoints. The URL comes from the adapter
 * and the exchange goes back through it, so this file stays correct whichever
 * networks are implemented -- and the adapters remain the only place that has
 * to be verified against live provider documentation.
 */
final class OAuthController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ProviderRegistry $providers,
        private readonly OAuthStateService $states,
        private readonly StoreSocialConnectionService $connections,
        private readonly ResolveAppCredentialService $credentials,
    ) {}

    /**
     * Leg one: send the user to the provider.
     */
    public function redirect(Request $request, string $provider): RedirectResponse
    {
        $request->user()->can('social_accounts.connect') || abort(403);

        if (! $this->providers->has($provider)) {
            return back()->with('error', 'That network is not available.');
        }

        $tenant = $this->context->get();

        $validated = $request->validate([
            // The brand this grant is being connected for. Proven to belong to
            // this tenant below rather than trusted from the query string.
            'customer' => ['required', 'integer'],
        ]);

        $brand = Customer::query()->find($validated['customer']);

        // The tenant scope already hides another agency's brand, so a missing
        // row and a foreign one are indistinguishable here -- which is the
        // point.
        abort_if($brand === null, 404);

        $request->user()->can('view', $brand) || abort(403);

        /*
         | The agency's own developer app for this network, if they have one.
         |
         | Null means the platform's app, which is the right answer for an
         | agency that has not supplied one -- and the reason a new tenant can
         | connect anything at all. The id is written onto the state row so the
         | callback exchanges the code against the SAME app that issued it,
         | even if the agency changes their credentials mid-flow.
         */
        $credential = $this->credentials->for($tenant->getKey(), $provider);

        ['context' => $oauth] = $this->states->issue(
            tenantId: $tenant->getKey(),
            userId: $request->user()->getKey(),
            providerKey: $provider,
            scopes: $this->requestedScopes($provider),
            customerId: $brand->getKey(),
            credentialId: $credential?->getKey(),
            redirectTo: route('agency.social.index', absolute: false),
            usePkce: (bool) config("social.providers.{$provider}.pkce", false),
        );

        try {
            $url = $this->providers->for($provider)->authorizationUrl($oauth);
        } catch (Throwable $e) {
            /*
             | An adapter that cannot build its own URL is a configuration
             | problem -- missing credentials, usually -- and the agency can do
             | nothing about it. Say so plainly rather than showing them a
             | provider error.
             */
            Log::error('Could not build an authorisation URL.', [
                'provider' => $provider,
                'exception' => $e->getMessage(),
            ]);

            return back()->with('error', 'That network is not configured yet.');
        }

        // away() rather than to(): this is an external host, and Laravel's
        // to() would prefix it with our own.
        return redirect()->away($url);
    }

    /**
     * Leg two: the provider sends the user back.
     */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        /*
         | Checked again on the way back, not only on the way out.
         |
         | The state is single-use and bound to this user, which already stops a
         | replay or a forwarded link -- but binding proves WHO, not whether
         | they may still connect. Someone whose permission was withdrawn while
         | they sat on the provider's consent screen would otherwise return with
         | a valid state and have the grant stored anyway.
         |
         | It is also what makes the authorisation visible to the enforcing test
         | in AgencyUiTest: an action whose only check is implicit reads exactly
         | like an action nobody remembered to protect.
         */
        $request->user()->can('social_accounts.connect') || abort(403);

        $landing = route('agency.social.index');

        /*
         | A user who declines consent comes back with an error and no code.
         | That is a decision, not a fault, so it reads as one.
         */
        if ($request->filled('error')) {
            return redirect($landing)->with('error', 'The connection was cancelled.');
        }

        $data = $request->validate([
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
        ]);

        try {
            /*
             | Consumed before anything else is done with the request. It is
             | single-use and bound to this user, so a replayed callback --
             | or one opened by somebody the link was forwarded to -- stops
             | here rather than after a token has been exchanged.
             */
            $oauth = $this->states->consume(
                $data['state'],
                $provider,
                (int) $request->user()->getKey(),
            );
        } catch (OAuthStateInvalid $e) {
            return redirect($landing)->with('error', $e->getMessage());
        }

        try {
            $tokens = $this->providers->for($provider)->exchangeCode($data['code'], $oauth);
        } catch (Throwable $e) {
            /*
             | The code is short-lived and single-use, so there is nothing to
             | retry with: the honest outcome is to ask them to start again.
             | The exception is logged without the code, which is a credential
             | for as long as it lives.
             */
            Log::error('OAuth code exchange failed.', [
                'provider' => $provider,
                'tenant_id' => $oauth->tenantId,
                'exception' => $e->getMessage(),
            ]);

            return redirect($landing)->with('error', 'That connection could not be completed. Please try again.');
        }

        $connection = $this->connections->storeConnection(
            $this->context->get(),
            $oauth,
            $tokens,
        );

        /*
         | Straight to the destination picker rather than connecting everything
         | found. One Meta grant can yield a dozen Pages, and silently attaching
         | all of them to a brand is how somebody posts a client's content to
         | the wrong Page.
         */
        return redirect()->route('agency.social.choose', $connection);
    }

    /**
     * Every scope this provider's account types require.
     *
     * Config nests required_scopes under each account type -- one Meta grant
     * covers Pages and the Instagram accounts behind them, and they do not ask
     * for the same things. Collected here rather than assumed, and every value
     * in that list is still marked [VERIFY] against live provider docs.
     *
     * An adapter is free to add to this when it builds its URL; it knows its
     * own network's rules, and this file deliberately does not.
     *
     * @return list<string>
     */
    private function requestedScopes(string $provider): array
    {
        $config = (array) config("social.providers.{$provider}", []);
        $types = (array) ($config['account_types'] ?? []);

        $scopes = [];

        foreach ($types as $type) {
            foreach ((array) ($config[$type]['required_scopes'] ?? []) as $scope) {
                $scopes[] = (string) $scope;
            }
        }

        return array_values(array_unique($scopes));
    }
}
