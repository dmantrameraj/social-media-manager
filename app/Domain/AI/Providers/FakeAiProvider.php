<?php

declare(strict_types=1);

namespace App\Domain\AI\Providers;

use App\Domain\AI\Contracts\AiProviderInterface;
use App\Domain\AI\DTO\AiRequest;
use App\Domain\AI\DTO\AiResponse;
use App\Domain\AI\Exceptions\AiProviderException;

/**
 * Scriptable in-memory provider.
 *
 * Exists so the credit accounting, brand grounding and failure handling can be
 * proven without an API key and without spending money on every test run. Real
 * adapters are held to the same contract this one implements.
 *
 * Registered outside production only.
 */
final class FakeAiProvider implements AiProviderInterface
{
    private static array $scripted = [];

    private static array $capturedRequests = [];

    private static int $callCount = 0;

    public function key(): string
    {
        return 'fake';
    }

    public function defaultModel(): string
    {
        return 'fake-model-1';
    }

    public static function reset(): void
    {
        self::$scripted = [];
        self::$capturedRequests = [];
        self::$callCount = 0;
    }

    public static function willReturn(string $content, int $completionTokens = 50): void
    {
        self::$scripted[] = ['content' => $content, 'tokens' => $completionTokens];
    }

    public static function willFail(bool $retryable = true, string $message = 'Scripted AI failure'): void
    {
        self::$scripted[] = new AiProviderException($message, retryable: $retryable);
    }

    public static function callCount(): int
    {
        return self::$callCount;
    }

    /** The prompts actually sent -- used to assert what reached the model. */
    public static function capturedRequests(): array
    {
        return self::$capturedRequests;
    }

    public static function lastRequest(): ?AiRequest
    {
        return self::$capturedRequests[array_key_last(self::$capturedRequests)] ?? null;
    }

    public function generate(AiRequest $request): AiResponse
    {
        self::$callCount++;
        self::$capturedRequests[] = $request;

        $next = array_shift(self::$scripted);

        if ($next instanceof AiProviderException) {
            throw $next;
        }

        $content = $next['content'] ?? 'Generated content.';
        $tokens = (int) ($next['tokens'] ?? 50);

        return new AiResponse(
            content: $content,
            promptTokens: 100,
            completionTokens: $tokens,
            model: $this->defaultModel(),
            stopReason: 'end_turn',
            latencyMs: 5,
        );
    }

    public function estimateCredits(AiRequest $request): int
    {
        return 1;
    }
}
