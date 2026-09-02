<?php

declare(strict_types=1);

namespace App\Domain\AI\Services;

use App\Domain\AI\BrandBrain\BrandBrainContextBuilder;
use App\Domain\AI\Contracts\AiFeatureInterface;
use App\Domain\AI\Contracts\AiProviderInterface;
use App\Domain\AI\Credits\CreditLedger;
use App\Domain\AI\DTO\AiResponse;
use App\Domain\AI\Exceptions\AiProviderException;
use App\Domain\AI\Models\AiGeneration;
use App\Domain\AI\Models\BrandBrain;
use App\Domain\Audit\SecretRedactor;
use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Str;

/**
 * Runs one AI generation end to end.
 *
 * Order matters and is the whole point of this class:
 *
 *   entitlement -> reserve credits -> build prompt -> call provider
 *                                  -> commit or release -> log
 *
 * Credits are reserved BEFORE the provider is called, so concurrent requests
 * cannot overspend, and released on failure, so a failed generation is never
 * charged. See docs/08-AI-ARCHITECTURE.md §5.
 */
final class GenerateContentService
{
    public function __construct(
        private readonly AiProviderInterface $provider,
        private readonly CreditLedger $ledger,
        private readonly EntitlementResolver $entitlements,
        private readonly BrandBrainContextBuilder $contextBuilder,
        private readonly SecretRedactor $redactor,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> the feature's parsed result
     */
    public function execute(
        AiFeatureInterface $feature,
        Customer $customer,
        User $actor,
        array $input = [],
        ?string $idempotencyKey = null,
    ): array {
        $tenant = $customer->tenant;

        // Fails before anything is reserved or sent.
        $this->entitlements->guard($tenant, 'ai.credits_per_month');

        $cost = $this->costOf($feature);
        $idempotencyKey ??= (string) Str::ulid();

        $generation = $this->openGeneration($feature, $customer, $actor);

        // Throws InsufficientCredits before any provider call, so an
        // unaffordable request never costs real money.
        $reservation = $this->ledger->reserve(
            $tenant,
            $cost,
            "AI: {$feature->key()}",
            $generation,
            'reserve:'.$idempotencyKey,
            $actor->getKey(),
        );

        try {
            $context = $this->contextBuilder->build($customer, $feature->requiredBrainSections());
            $request = $feature->buildRequest($input, $context);

            $response = $this->provider->generate($request);

            $charged = $this->actualCost($cost, $response);

            $this->ledger->commit(
                $tenant,
                $cost,
                $charged,
                "AI: {$feature->key()}",
                $generation,
                'consume:'.$idempotencyKey,
                $actor->getKey(),
            );

            $result = $feature->parseResponse($response);
            $result['warnings'] = $this->forbiddenWordWarnings($customer, $result);

            $this->closeGeneration($generation, $response, $charged, $request->system);

            return $result;
        } catch (AiProviderException $e) {
            // A failed generation is not charged.
            $this->ledger->release($tenant, $cost, 'AI generation failed', $generation, 'release:'.$idempotencyKey);
            $this->failGeneration($generation, $e);

            throw $e;
        }
    }

    private function costOf(AiFeatureInterface $feature): int
    {
        $costs = (array) config('ai.costs', []);

        return max(1, (int) ($costs[$feature->key()] ?? 1));
    }

    /**
     * Flat per-feature cost, plus overage only for unusually long outputs.
     *
     * Predictable for the customer in the common case; protects margin on the
     * outliers.
     */
    private function actualCost(int $baseCost, AiResponse $response): int
    {
        if (! config('ai.token_overage.enabled', true)) {
            return $baseCost;
        }

        $perCredit = max(1, (int) config('ai.token_overage.tokens_per_credit', 2000));
        $overage = (int) floor($response->completionTokens / $perCredit);

        return $baseCost + max(0, $overage);
    }

    /**
     * Forbidden words are checked in post-processing as well as instructed in
     * the prompt, because models do not reliably honour negative constraints.
     *
     * Reported to the user rather than silently rewritten: the agency should
     * decide what to do about it.
     *
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    private function forbiddenWordWarnings(Customer $customer, array $result): array
    {
        $brain = BrandBrain::query()->where('customer_id', $customer->getKey())->first();

        if ($brain === null || $brain->forbiddenWords() === []) {
            return [];
        }

        $haystack = Str::lower(json_encode($result, JSON_UNESCAPED_UNICODE) ?: '');
        $warnings = [];

        foreach ($brain->forbiddenWords() as $word) {
            if ($word !== '' && str_contains($haystack, Str::lower($word))) {
                $warnings[] = "The generated content contains a forbidden word: \"{$word}\".";
            }
        }

        return $warnings;
    }

    private function openGeneration(
        AiFeatureInterface $feature,
        Customer $customer,
        User $actor,
    ): AiGeneration {
        return AiGeneration::query()->forceCreate([
            'ulid' => (string) Str::ulid(),
            'tenant_id' => $customer->tenant_id,
            'customer_id' => $customer->getKey(),
            'user_id' => $actor->getKey(),
            'feature' => $feature->key(),
            'provider' => $this->provider->key(),
            'status' => 'pending',
            'created_at' => now(),
        ]);
    }

    private function closeGeneration(
        AiGeneration $generation,
        AiResponse $response,
        int $charged,
        string $systemPrompt,
    ): void {
        $generation->forceFill([
            'status' => 'succeeded',
            'model' => $response->model,
            'prompt_tokens' => $response->promptTokens,
            'completion_tokens' => $response->completionTokens,
            'credits_charged' => $charged,
            'latency_ms' => $response->latencyMs,
            // Redacted and retention-limited: these hold customer business
            // content, not just diagnostics.
            'request_snapshot' => $this->redactor->redact(['system' => $systemPrompt]),
            'response_snapshot' => $this->redactor->redact(['content' => $response->content]),
        ])->save();
    }

    private function failGeneration(AiGeneration $generation, AiProviderException $e): void
    {
        $generation->forceFill([
            'status' => 'failed',
            'error_code' => $e->providerCode,
            'error_message' => $e->getMessage(),
        ])->save();
    }
}
