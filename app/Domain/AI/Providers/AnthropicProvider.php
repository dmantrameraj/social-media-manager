<?php

declare(strict_types=1);

namespace App\Domain\AI\Providers;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIStatusException;
use App\Domain\AI\Contracts\AiProviderInterface;
use App\Domain\AI\DTO\AiRequest;
use App\Domain\AI\DTO\AiResponse;
use App\Domain\AI\Exceptions\AiProviderException;
use Throwable;

/**
 * Anthropic (Claude) adapter.
 *
 * The only class in the codebase that names a vendor. Everything above it
 * speaks AiRequest/AiResponse.
 */
final class AnthropicProvider implements AiProviderInterface
{
    private ?Client $client = null;

    public function key(): string
    {
        return 'anthropic';
    }

    public function defaultModel(): string
    {
        return (string) config('ai.providers.anthropic.model', 'claude-opus-5');
    }

    public function generate(AiRequest $request): AiResponse
    {
        $startedAt = microtime(true);
        $model = $request->model ?? $this->defaultModel();

        try {
            $message = $this->client()->messages->create(
                model: $model,
                maxTokens: $request->maxTokens,
                // A single cached block: the system prompt carries the brand
                // context, which is identical across every generation for that
                // brand and is the largest part of the request.
                system: [[
                    'type' => 'text',
                    'text' => $request->system,
                    'cacheControl' => ['type' => 'ephemeral'],
                ]],
                messages: $this->formatMessages($request),
                // Adaptive is the current mode; budgetTokens is rejected on
                // this model family.
                thinking: ['type' => 'adaptive'],
            );
        } catch (APIStatusException $e) {
            throw $this->mapStatusException($e);
        } catch (Throwable $e) {
            throw new AiProviderException(
                'The AI service could not be reached.',
                retryable: true,
                previous: $e,
            );
        }

        return new AiResponse(
            content: $this->extractText($message),
            promptTokens: (int) ($message->usage->inputTokens ?? 0),
            completionTokens: (int) ($message->usage->outputTokens ?? 0),
            model: (string) ($message->model ?? $model),
            stopReason: (string) ($message->stopReason ?? 'end_turn'),
            latencyMs: (int) round((microtime(true) - $startedAt) * 1000),
        );
    }

    public function estimateCredits(AiRequest $request): int
    {
        // Deliberately crude: the flat per-feature cost in config/ai.php is
        // what a customer is actually charged. This only sizes the
        // reservation, and over-reserving briefly is safer than under.
        $approximateTokens = (int) ceil(
            (mb_strlen($request->system) + $this->messageLength($request)) / 4
        ) + $request->maxTokens;

        $perCredit = max(1, (int) config('ai.token_overage.tokens_per_credit', 2000));

        return max(1, (int) ceil($approximateTokens / $perCredit));
    }

    // ------------------------------------------------------------- internals

    private function client(): Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $apiKey = (string) config('ai.providers.anthropic.api_key');

        if ($apiKey === '') {
            // Fails before anything is charged or reserved.
            throw new AiProviderException(
                'The AI service is not configured.',
                retryable: false,
            );
        }

        return $this->client = new Client(apiKey: $apiKey);
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function formatMessages(AiRequest $request): array
    {
        $messages = $request->messages;

        // Structured features ask for JSON in the final user turn rather than
        // via assistant prefill, which this model family rejects.
        if ($request->expectsJson()) {
            $schema = json_encode($request->jsonSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            $messages[] = [
                'role' => 'user',
                'content' => "Respond with JSON matching this schema, and nothing else:\n{$schema}",
            ];
        }

        return $messages;
    }

    /**
     * Content is a list of polymorphic blocks; a thinking block can precede
     * the text, so reading content[0] blindly would break with adaptive
     * thinking enabled.
     */
    private function extractText(mixed $message): string
    {
        $text = '';

        foreach ($message->content ?? [] as $block) {
            if (($block->type ?? null) === 'text') {
                $text .= $block->text;
            }
        }

        return trim($text);
    }

    private function messageLength(AiRequest $request): int
    {
        return array_sum(array_map(
            static fn (array $m): int => mb_strlen($m['content']),
            $request->messages,
        ));
    }

    /**
     * Map vendor errors onto our retryable/permanent split, so the calling
     * service knows whether releasing the credit reservation and retrying is
     * worthwhile.
     */
    private function mapStatusException(APIStatusException $e): AiProviderException
    {
        $type = $e->type->value ?? '';

        return match (true) {
            $type === 'rate_limit_error' => new AiProviderException(
                'The AI service is rate limited. Please try again shortly.',
                retryable: true, providerCode: $type, previous: $e,
            ),
            $type === 'overloaded_error' => new AiProviderException(
                'The AI service is busy. Please try again shortly.',
                retryable: true, providerCode: $type, previous: $e,
            ),
            $type === 'authentication_error' => new AiProviderException(
                'The AI service rejected our credentials.',
                retryable: false, providerCode: $type, previous: $e,
            ),
            $type === 'invalid_request_error' => new AiProviderException(
                'The AI request was not valid.',
                retryable: false, providerCode: $type, previous: $e,
            ),
            default => new AiProviderException(
                'The AI service returned an error.',
                retryable: true, providerCode: $type !== '' ? $type : null, previous: $e,
            ),
        };
    }
}
