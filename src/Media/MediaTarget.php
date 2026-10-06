<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Media;

/**
 * What an uploaded file belongs to: a field of a trace or observation, or a field of
 * a dataset item. Langfuse accepts exactly one of the two.
 */
final readonly class MediaTarget
{
    /**
     * @param array<string, string> $fields
     */
    private function __construct(
        private array $fields,
    ) {}

    /**
     * @param 'input'|'output'|'metadata' $field
     */
    public static function trace(string $traceId, string $field = 'input', ?string $observationId = null): self
    {
        return new self(array_filter(
            ['traceId' => $traceId, 'observationId' => $observationId, 'field' => $field],
            static fn(?string $value): bool => $value !== null,
        ));
    }

    /**
     * The dataset item does not have to exist yet: upload first, then create the item
     * with the returned reference in the same field.
     *
     * @param string $datasetId the dataset's ID (not its name)
     * @param 'input'|'expectedOutput'|'metadata' $field
     */
    public static function datasetItem(string $datasetId, string $datasetItemId, string $field = 'input'): self
    {
        return new self(['datasetId' => $datasetId, 'datasetItemId' => $datasetItemId, 'field' => $field]);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->fields;
    }
}
