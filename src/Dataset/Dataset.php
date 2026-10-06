<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Dataset;

use Mentax\LangfuseClient\Internal\ArrayReader;

final readonly class Dataset
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description = null,
        public array $metadata = [],
    ) {}

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $reader = new ArrayReader($data, 'Langfuse dataset');
        $metadata = $reader->mixed('metadata');

        return new self(
            $reader->string('id'),
            $reader->string('name'),
            $reader->nullableString('description'),
            is_array($metadata) && !array_is_list($metadata) ? $reader->map('metadata') : [],
        );
    }
}
