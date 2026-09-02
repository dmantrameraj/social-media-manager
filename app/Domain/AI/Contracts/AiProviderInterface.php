<?php

declare(strict_types=1);

namespace App\Domain\AI\Contracts;

use App\Domain\AI\DTO\AiRequest;
use App\Domain\AI\DTO\AiResponse;
use App\Domain\AI\Exceptions\AiProviderException;

/**
 * The seam between AI features and whichever vendor is behind them.
 *
 * No feature class knows which vendor is in use, and no vendor adapter knows
 * what a brand is. Adding a second provider means implementing this and adding
 * a config entry -- no feature changes.
 *
 * See docs/08-AI-ARCHITECTURE.md §2.
 */
interface AiProviderInterface
{
    public function key(): string;

    /**
     * @throws AiProviderException on any vendor failure, mapped to our own
     *                             retryable/permanent classification
     */
    public function generate(AiRequest $request): AiResponse;

    /**
     * Credits this request is expected to cost, before it runs.
     *
     * Used to size the reservation. The actual charge is computed from the
     * response, because the estimate is necessarily approximate.
     */
    public function estimateCredits(AiRequest $request): int;

    public function defaultModel(): string;
}
