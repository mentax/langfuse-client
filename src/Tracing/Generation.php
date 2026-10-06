<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Tracing;

use DateTimeImmutable;
use InvalidArgumentException;
use Mentax\LangfuseClient\Prompt\Prompt;

/**
 * A model call: model name and parameters, token usage, cost and the prompt version used.
 */
final class Generation extends Observation
{
    private ?Usage $usage = null;

    /** @var array<string, float|int> */
    private array $cost = [];

    private ?DateTimeImmutable $completionStartTime = null;

    private ?string $promptName = null;

    private ?int $promptVersion = null;

    /**
     * @param array<string, mixed> $modelParameters
     * @param array<string, mixed> $metadata
     *
     * @internal created through Observation::startGeneration()
     */
    public function __construct(
        Tracer $tracer,
        TraceContext $context,
        ?string $parentId,
        string $name,
        private ?string $model = null,
        mixed $input = null,
        private array $modelParameters = [],
        ?Prompt $prompt = null,
        array $metadata = [],
    ) {
        parent::__construct($tracer, $context, $parentId, $name, ObservationType::Generation, $input, $metadata);
        if ($prompt !== null) {
            $this->linkPrompt($prompt->name, $prompt->version);
        }
    }

    public function setModel(string $model): static
    {
        $this->model = $model;

        return $this;
    }

    /**
     * @param array<string, mixed> $modelParameters
     */
    public function setModelParameters(array $modelParameters): static
    {
        $this->modelParameters = $modelParameters;

        return $this;
    }

    public function setUsage(Usage $usage): static
    {
        $this->usage = $usage;

        return $this;
    }

    /**
     * Cost in USD per usage key, e.g. ['input' => 0.0012, 'output' => 0.0034, 'total' => 0.0046].
     * Leave it out to let Langfuse calculate cost from the model definition.
     *
     * @param array<string, float|int> $cost
     */
    public function setCost(array $cost): static
    {
        foreach ($cost as $key => $value) {
            if ($value < 0 || (is_float($value) && !is_finite($value))) {
                throw new InvalidArgumentException(sprintf('Cost "%s" must be a finite, non-negative number.', $key));
            }
        }
        $this->cost = $cost;

        return $this;
    }

    /**
     * Records when the first token arrived (time to first token) for streamed responses.
     */
    public function markCompletionStart(): static
    {
        $this->completionStartTime = $this->tracer->now();

        return $this;
    }

    /**
     * Links the generation to a prompt version. Langfuse resolves the link only if a
     * prompt with exactly this name and version exists in the project.
     */
    public function linkPrompt(string $name, int $version): static
    {
        $this->promptName = $name;
        $this->promptVersion = $version;

        return $this;
    }

    public function end(mixed $output = null, ?Usage $usage = null): void
    {
        if ($usage !== null && !$this->isEnded()) {
            $this->usage = $usage;
        }
        parent::end($output);
    }

    public function attributes(): array
    {
        $attributes = parent::attributes();

        if ($this->model !== null) {
            $attributes['langfuse.observation.model.name'] = $this->model;
        }
        if ($this->modelParameters !== []) {
            $attributes['langfuse.observation.model.parameters'] = Json::encodeObject($this->modelParameters);
        }
        if ($this->usage !== null && $this->usage->toArray() !== []) {
            $attributes['langfuse.observation.usage_details'] = Json::encodeObject($this->usage->toArray());
        }
        if ($this->cost !== []) {
            $attributes['langfuse.observation.cost_details'] = Json::encodeObject($this->cost);
        }
        if ($this->completionStartTime !== null) {
            $attributes['langfuse.observation.completion_start_time'] = $this->completionStartTime->format('Y-m-d\TH:i:s.vP');
        }
        if ($this->promptName !== null && $this->promptVersion !== null) {
            $attributes['langfuse.observation.prompt.name'] = $this->promptName;
            $attributes['langfuse.observation.prompt.version'] = $this->promptVersion;
        }

        return $attributes;
    }
}
