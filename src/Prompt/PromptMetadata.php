<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Prompt;

use Mentax\LangfuseClient\Exception\InvalidResponseException;
use Mentax\LangfuseClient\Internal\ArrayReader;

/**
 * One prompt as listed by GET /api/public/v2/prompts: no content, only what exists.
 */
final readonly class PromptMetadata
{
    /**
     * @param list<int> $versions
     * @param list<string> $labels labels across all versions, including "latest"
     * @param list<string> $tags
     */
    public function __construct(
        public string $name,
        public PromptType $type,
        public array $versions = [],
        public array $labels = [],
        public array $tags = [],
    ) {}

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $reader = new ArrayReader($data, 'Langfuse prompt list entry');
        $name = $reader->string('name');
        $type = PromptType::tryFrom($reader->string('type'))
            ?? throw new InvalidResponseException(sprintf('Langfuse prompt "%s" has an unsupported type "%s".', $name, $reader->string('type')));

        return new self($name, $type, $reader->intList('versions'), $reader->stringList('labels'), $reader->stringList('tags'));
    }
}
