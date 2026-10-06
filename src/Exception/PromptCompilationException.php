<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Exception;

/**
 * The variables passed to compile() do not match the variables the prompt declares.
 */
final class PromptCompilationException extends LangfuseException
{
    /**
     * @param list<string> $missing    variables the prompt uses but the caller did not pass
     * @param list<string> $unexpected variables the caller passed but the prompt does not use
     */
    public function __construct(
        public readonly string $promptName,
        public readonly array $missing = [],
        public readonly array $unexpected = [],
    ) {
        $parts = [];
        if ($missing !== []) {
            $parts[] = 'missing: ' . implode(', ', $missing);
        }
        if ($unexpected !== []) {
            $parts[] = 'unexpected: ' . implode(', ', $unexpected);
        }

        parent::__construct(sprintf('Cannot compile prompt "%s" (%s).', $promptName, implode('; ', $parts)));
    }
}
