<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Dataset;

use Mentax\LangfuseClient\Internal\ArrayReader;
use Mentax\LangfuseClient\Media\MediaReference;

/**
 * One test case: input, expected output and metadata, as stored in Langfuse.
 * Input, expected output and metadata are arbitrary JSON values.
 */
final readonly class DatasetItem
{
    public function __construct(
        public string $id,
        public string $datasetId,
        public string $datasetName,
        public mixed $input,
        public mixed $expectedOutput,
        public mixed $metadata,
        public DatasetItemStatus $status = DatasetItemStatus::Active,
        public ?string $sourceTraceId = null,
        public ?string $sourceObservationId = null,
    ) {}

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $reader = new ArrayReader($data, 'Langfuse dataset item');

        return new self(
            id: $reader->string('id'),
            datasetId: $reader->string('datasetId'),
            datasetName: $reader->string('datasetName'),
            input: $reader->mixed('input'),
            expectedOutput: $reader->mixed('expectedOutput'),
            metadata: $reader->mixed('metadata'),
            status: DatasetItemStatus::tryFrom($reader->nullableString('status') ?? '') ?? DatasetItemStatus::Active,
            sourceTraceId: $reader->nullableString('sourceTraceId'),
            sourceObservationId: $reader->nullableString('sourceObservationId'),
        );
    }

    /**
     * Media references found anywhere in input, expected output or metadata, keyed by
     * a dotted path such as "input.photos.0".
     *
     * @return array<string, MediaReference>
     */
    public function mediaReferences(): array
    {
        $found = [];
        foreach (['input' => $this->input, 'expectedOutput' => $this->expectedOutput, 'metadata' => $this->metadata] as $field => $value) {
            self::collect($value, $field, $found);
        }

        return $found;
    }

    /**
     * @param array<string, MediaReference> $found
     */
    private static function collect(mixed $value, string $path, array &$found): void
    {
        if (is_string($value)) {
            $reference = MediaReference::tryParse($value);
            if ($reference !== null) {
                $found[$path] = $reference;
            }

            return;
        }
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                self::collect($child, $path . '.' . $key, $found);
            }
        }
    }
}
