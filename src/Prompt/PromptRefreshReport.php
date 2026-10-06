<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Prompt;

/**
 * Outcome of CachedPromptProvider::refreshAll(): which prompt labels were written to
 * the cache and which were not (the cache keeps serving its previous entry for those).
 */
final readonly class PromptRefreshReport
{
    /**
     * @param list<array{name: string, label: string, version: int}> $refreshed
     * @param list<array{name: string, label: string, reason: string}> $failed
     */
    public function __construct(
        public array $refreshed,
        public array $failed,
    ) {}

    public function isComplete(): bool
    {
        return $this->failed === [];
    }
}
