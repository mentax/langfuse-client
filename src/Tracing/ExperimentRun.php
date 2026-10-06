<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Tracing;

/**
 * One run of an experiment over a dataset, e.g. "prompt v7 on gemini-3.5-flash".
 *
 * Langfuse v4 builds runs from trace spans carrying langfuse.experiment.* attributes;
 * there is nothing to create up front. Traces started with Tracer::startExperimentTrace()
 * for the same run appear together under the dataset's runs.
 */
final readonly class ExperimentRun
{
    public string $id;

    /**
     * @param string $datasetId the dataset's ID (not its name)
     * @param string $name      unique per dataset; reusing a name adds traces to that run
     * @param array<string, mixed> $metadata e.g. prompt version and model, shown with the run
     */
    public function __construct(
        public string $datasetId,
        public string $name,
        public ?string $description = null,
        public array $metadata = [],
        ?string $id = null,
    ) {
        $this->id = $id ?? self::stableId($datasetId, $name);
    }

    /**
     * Same dataset and name always give the same run ID, like Langfuse's own SDKs.
     */
    public static function stableId(string $datasetId, string $name): string
    {
        return substr(hash('sha256', json_encode(['langfuse-experiment', $datasetId, $name], JSON_THROW_ON_ERROR)), 0, 16);
    }
}
