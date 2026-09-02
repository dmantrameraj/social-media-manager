<?php

declare(strict_types=1);

namespace App\Domain\AI\Features\Concerns;

use App\Domain\AI\DTO\AiRequest;
use App\Domain\AI\DTO\AiResponse;

/**
 * Shared shape for features that take one piece of text and return one piece
 * of text: rewrite, tone adjustment, translation.
 *
 * Extracted because those three genuinely differ only in their instruction --
 * not as speculative abstraction. Features with different output shapes
 * (structured lists, plans) deliberately do not use this.
 */
trait TransformsText
{
    /**
     * The instruction that makes this transform what it is.
     *
     * @param  array<string, mixed>  $input
     */
    abstract protected function instruction(array $input): string;

    /**
     * @param  array<string, mixed>  $input
     */
    protected function buildTransformRequest(array $input, string $brandContext): AiRequest
    {
        $text = trim((string) ($input['text'] ?? ''));

        return new AiRequest(
            system: $brandContext."\n\n"
                .$this->instruction($input)."\n"
                .'Preserve the original meaning and any factual claims exactly. '
                .'Do not add offers, prices or claims that are not already present. '
                .'Return only the resulting text, with no preamble, no surrounding '
                .'quotation marks and no explanation.',
            messages: [[
                'role' => 'user',
                'content' => $text !== ''
                    ? $text
                    : 'No text was supplied.',
            ]],
            // Roughly proportional to the input, with headroom: a transform
            // that truncates mid-sentence is worse than one that costs a
            // little more.
            maxTokens: $this->tokenBudgetFor($text),
            meta: ['feature' => $this->key()],
        );
    }

    protected function tokenBudgetFor(string $text): int
    {
        $approximate = (int) ceil(mb_strlen($text) / 4) * 2;

        return max(512, min(4096, $approximate));
    }

    public function parseResponse(AiResponse $response): array
    {
        return ['text' => trim($response->content, " \t\n\r\0\x0B\"'")];
    }
}
