<?php

declare(strict_types=1);

namespace App\Domain\Publishing\Services;

use App\Domain\Audit\SecretRedactor;
use App\Domain\Publishing\Enums\AttemptOutcome;
use App\Domain\Publishing\Enums\TargetStatus;
use App\Domain\Publishing\Models\PostTarget;
use App\Domain\Publishing\Models\PublicationAttempt;
use App\Domain\Social\Contracts\SupportsRecentPostLookup;
use App\Domain\Social\DTO\PublishPayload;
use App\Domain\Social\Enums\ProviderErrorClass;
use App\Domain\Social\Exceptions\ProviderException;
use App\Domain\Social\ProviderRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Publishes one already-claimed target.
 *
 * Assumes the caller holds the claim. Everything here is about doing the work
 * exactly once and classifying what happened when it does not.
 *
 * See docs/06-PUBLISHING-ENGINE.md §6 and §8.
 */
final class PublishPostTargetService
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ClaimPostTargetService $claims,
        private readonly SecretRedactor $redactor,
    ) {}

    public function execute(PostTarget $target, PublishPayload $payload): TargetStatus
    {
        $account = $target->socialAccount;
        $provider = $this->registry->for($target->provider_key);

        // Preconditions are re-checked here, not only at scheduling: between
        // being scheduled and being published, media can be deleted, a scope
        // revoked, or a connection expired.
        if (! $account->canPublish()) {
            $this->pause($target, TargetStatus::PausedReconnect);

            return TargetStatus::PausedReconnect;
        }

        $validation = $provider->validate($payload, $account);

        if ($validation->failed()) {
            // Validation failures are permanent: retrying identical content
            // against identical rules cannot succeed.
            $this->fail(
                $target,
                ProviderErrorClass::Validation,
                (string) $validation->firstMessage(),
                permanent: true,
            );

            return TargetStatus::Failed;
        }

        $attempt = $this->openAttempt($target);

        try {
            $result = $provider->publish($payload, $account);

            $this->succeed($target, $attempt, $result->externalId, $result->externalUrl);

            return TargetStatus::Published;
        } catch (ProviderException $e) {
            return $this->handleProviderFailure($target, $attempt, $e, $payload);
        }
    }

    /**
     * Classify a provider failure and decide what happens next.
     */
    private function handleProviderFailure(
        PostTarget $target,
        PublicationAttempt $attempt,
        ProviderException $e,
        PublishPayload $payload,
    ): TargetStatus {
        // The provider says this is a duplicate. If an external id came back,
        // the post exists and this is a SUCCESS, not a failure.
        if ($e->errorClass === ProviderErrorClass::Duplicate) {
            $recovered = $e->recoveredExternalId()
                ?? $this->findExistingPost($target, $payload);

            if ($recovered !== null) {
                $this->succeed($target, $attempt, $recovered, null);

                return TargetStatus::Published;
            }

            // A duplicate we cannot resolve must NOT be auto-retried: a human
            // has to check the platform first.
            $this->closeAttempt($attempt, AttemptOutcome::PermanentFailure, $e);
            $this->markFailed($target, $e->errorClass, 'duplicate_unresolved', $e->getMessage());

            return TargetStatus::Failed;
        }

        // Expired or insufficient authorisation: the content is fine, so the
        // target waits for a reconnect instead of burning its retries.
        if ($e->requiresReconnect()) {
            $this->closeAttempt($attempt, AttemptOutcome::PermanentFailure, $e);
            $this->pause($target, TargetStatus::PausedReconnect, $e);

            return TargetStatus::PausedReconnect;
        }

        // Rate limiting is not the tenant's fault, so it does not consume the
        // retry budget -- a busy account must not exhaust its attempts waiting.
        if ($e->errorClass === ProviderErrorClass::RateLimit) {
            $this->closeAttempt($attempt, AttemptOutcome::RetryableFailure, $e);

            $this->claims->release(
                $target,
                $e->retryAfter ?? now()->addSeconds(60),
            );

            return TargetStatus::Scheduled;
        }

        if (! $e->isRetryable()) {
            $this->closeAttempt($attempt, AttemptOutcome::PermanentFailure, $e);
            $this->markFailed($target, $e->errorClass, $e->providerCode, $e->getMessage());

            return TargetStatus::Failed;
        }

        // Retryable. Count the attempt and either back off or give up.
        $this->closeAttempt($attempt, AttemptOutcome::RetryableFailure, $e);

        $attemptsUsed = (int) $target->attempts + 1;
        $backoff = (array) config('publishing.backoff', [60, 300, 900]);
        $maxAttempts = (int) $target->max_attempts;

        if ($attemptsUsed >= $maxAttempts) {
            $this->markFailed($target, $e->errorClass, $e->providerCode, $e->getMessage(), $attemptsUsed);

            return TargetStatus::Failed;
        }

        $delay = $backoff[$attemptsUsed - 1] ?? end($backoff);

        PostTarget::query()->acrossTenants()->whereKey($target->getKey())->update([
            'status' => TargetStatus::Scheduled->value,
            'attempts' => $attemptsUsed,
            'next_attempt_at' => now()->addSeconds((int) $delay),
            'locked_at' => null,
            'locked_by' => null,
            'last_error_class' => $e->errorClass->value,
            'last_error_code' => $e->providerCode,
            'last_error_message' => $e->getMessage(),
            'updated_at' => now(),
        ]);

        $target->refresh();

        return TargetStatus::Scheduled;
    }

    /**
     * Ask the provider whether the post already exists.
     *
     * This is what turns "the worker died after the platform accepted it" from
     * a duplicate into a recovery. Only possible where the provider can list
     * recent posts.
     */
    private function findExistingPost(PostTarget $target, PublishPayload $payload): ?string
    {
        $provider = $this->registry->for($target->provider_key);

        if (! $provider instanceof SupportsRecentPostLookup) {
            return null;
        }

        return $provider->findRecentPostByFingerprint(
            $target->socialAccount,
            (string) $payload->idempotencyKey,
        );
    }

    private function openAttempt(PostTarget $target): PublicationAttempt
    {
        return PublicationAttempt::query()->forceCreate([
            'tenant_id' => $target->tenant_id,
            'post_target_id' => $target->getKey(),
            'attempt_no' => (int) $target->attempts + 1,
            'started_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function closeAttempt(
        PublicationAttempt $attempt,
        AttemptOutcome $outcome,
        ?ProviderException $e = null,
    ): void {
        $attempt->forceFill([
            'finished_at' => now(),
            'outcome' => $outcome->value,
            'http_status' => $e?->httpStatus,
            'error_class' => $e?->errorClass->value,
            'error_code' => $e?->providerCode,
            'error_message' => $e?->getMessage(),
            // Redacted: a raw provider response can echo back tokens.
            'response_snapshot' => $e !== null
                ? $this->redactor->redact($e->context)
                : null,
        ])->save();
    }

    private function succeed(
        PostTarget $target,
        PublicationAttempt $attempt,
        string $externalId,
        ?string $externalUrl,
    ): void {
        DB::transaction(function () use ($target, $attempt, $externalId, $externalUrl): void {
            $this->closeAttempt($attempt, AttemptOutcome::Success);

            PostTarget::query()->acrossTenants()->whereKey($target->getKey())->update([
                'status' => TargetStatus::Published->value,
                'external_post_id' => $externalId,
                'external_url' => $externalUrl,
                'published_at' => now(),
                'locked_at' => null,
                'locked_by' => null,
                'last_error_class' => null,
                'last_error_code' => null,
                'last_error_message' => null,
                'updated_at' => now(),
            ]);
        });

        $target->refresh();
    }

    private function fail(
        PostTarget $target,
        ProviderErrorClass $class,
        string $message,
        bool $permanent = true,
    ): void {
        $this->markFailed($target, $class, null, $message);
    }

    private function markFailed(
        PostTarget $target,
        ProviderErrorClass $class,
        ?string $code,
        string $message,
        ?int $attempts = null,
    ): void {
        PostTarget::query()->acrossTenants()->whereKey($target->getKey())->update([
            'status' => TargetStatus::Failed->value,
            'attempts' => $attempts ?? $target->attempts,
            'locked_at' => null,
            'locked_by' => null,
            'next_attempt_at' => null,
            'last_error_class' => $class->value,
            'last_error_code' => $code,
            'last_error_message' => $message,
            'updated_at' => now(),
        ]);

        $target->refresh();
    }

    private function pause(
        PostTarget $target,
        TargetStatus $status,
        ?ProviderException $e = null,
    ): void {
        PostTarget::query()->acrossTenants()->whereKey($target->getKey())->update([
            'status' => $status->value,
            'locked_at' => null,
            'locked_by' => null,
            'last_error_class' => $e?->errorClass->value,
            'last_error_message' => $e?->getMessage(),
            'updated_at' => now(),
        ]);

        $target->refresh();
    }
}
