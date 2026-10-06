<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Tracing;

use DateTimeImmutable;
use Mentax\LangfuseClient\Prompt\Prompt;
use Throwable;

/**
 * One unit of work inside a trace: a span, a model call (Generation) or another
 * Langfuse observation type.
 *
 * Nothing is sent until the observation is ended and the tracer flushes. Changes
 * made after end() are included only if they happen before the next flush.
 */
class Observation
{
    private const STATUS_ERROR = 2;

    private readonly string $id;

    private readonly DateTimeImmutable $startTime;

    private ?DateTimeImmutable $endTime = null;

    private mixed $output = null;

    private ?ObservationLevel $level = null;

    private ?string $statusMessage = null;

    private bool $failed = false;

    /** @var list<FileReference> */
    private array $files = [];

    /**
     * @param array<string, mixed> $metadata
     *
     * @internal created through Tracer::startTrace() and the start*() methods
     */
    public function __construct(
        protected readonly Tracer $tracer,
        protected readonly TraceContext $context,
        private readonly ?string $parentId,
        private readonly string $name,
        private readonly ObservationType $type,
        private mixed $input = null,
        private array $metadata = [],
    ) {
        $this->id = $tracer->newSpanId();
        $this->startTime = $tracer->now();
        $tracer->opened($this);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function traceId(): string
    {
        return $this->context->traceId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): ObservationType
    {
        return $this->type;
    }

    public function isEnded(): bool
    {
        return $this->endTime !== null;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function startSpan(
        string $name,
        mixed $input = null,
        array $metadata = [],
        ObservationType $type = ObservationType::Span,
    ): Observation {
        return new Observation($this->tracer, $this->context, $this->id, $name, $type, $input, $metadata);
    }

    /**
     * Starts a model call. Pass the Langfuse prompt it was compiled from to link the
     * generation to that prompt version in Langfuse (metrics per prompt version).
     *
     * @param array<string, mixed> $modelParameters e.g. temperature, maxOutputTokens
     * @param array<string, mixed> $metadata
     */
    public function startGeneration(
        string $name,
        ?string $model = null,
        mixed $input = null,
        array $modelParameters = [],
        ?Prompt $prompt = null,
        array $metadata = [],
    ): Generation {
        return new Generation($this->tracer, $this->context, $this->id, $name, $model, $input, $modelParameters, $prompt, $metadata);
    }

    /**
     * Records a point-in-time event: an observation that starts and ends immediately.
     *
     * @param array<string, mixed> $metadata
     */
    public function event(string $name, mixed $input = null, array $metadata = [], ?ObservationLevel $level = null): Observation
    {
        $event = new Observation($this->tracer, $this->context, $this->id, $name, ObservationType::Event, $input, $metadata);
        if ($level !== null) {
            $event->setLevel($level);
        }
        $event->end();

        return $event;
    }

    public function setInput(mixed $input): static
    {
        $this->input = $input;

        return $this;
    }

    public function setOutput(mixed $output): static
    {
        $this->output = $output;

        return $this;
    }

    /**
     * Merges keys into the metadata; existing keys are overwritten.
     *
     * @param array<string, mixed> $metadata
     */
    public function addMetadata(array $metadata): static
    {
        $this->metadata = array_replace($this->metadata, $metadata);

        return $this;
    }

    /**
     * Records files sent with this call, by reference only. They appear in the
     * observation metadata under "attachments"; the bytes are never sent to Langfuse.
     */
    public function attachFile(FileReference ...$files): static
    {
        $this->files = [...$this->files, ...array_values($files)];

        return $this;
    }

    public function setLevel(ObservationLevel $level, ?string $statusMessage = null): static
    {
        $this->level = $level;
        if ($statusMessage !== null) {
            $this->statusMessage = $statusMessage;
        }

        return $this;
    }

    /**
     * Marks the observation as failed (level ERROR, OTel status ERROR) and ends it.
     */
    public function fail(Throwable|string $error): void
    {
        $message = $error instanceof Throwable
            ? sprintf('%s: %s', $error::class, $error->getMessage())
            : $error;

        $this->failed = true;
        $this->setLevel(ObservationLevel::Error, $message);
        $this->end();
    }

    /**
     * Ends the observation; a second call does nothing.
     *
     * @param mixed $output stored only when not null
     */
    public function end(mixed $output = null): void
    {
        if ($this->endTime !== null) {
            return;
        }
        if ($output !== null) {
            $this->output = $output;
        }
        $this->endTime = $this->tracer->now();
        $this->tracer->ended($this);
    }

    /**
     * @internal
     */
    public function parentId(): ?string
    {
        return $this->parentId;
    }

    /**
     * @internal
     */
    public function startTime(): DateTimeImmutable
    {
        return $this->startTime;
    }

    /**
     * @internal
     */
    public function endTime(): ?DateTimeImmutable
    {
        return $this->endTime;
    }

    /**
     * @internal
     */
    public function statusCode(): ?int
    {
        return $this->failed ? self::STATUS_ERROR : null;
    }

    /**
     * @internal
     */
    public function statusMessage(): ?string
    {
        return $this->statusMessage;
    }

    /**
     * Langfuse span attributes of this observation, before OTLP encoding.
     * Arrays other than lists of strings are sent as JSON strings.
     *
     * @internal
     *
     * @return array<string, string|int|float|bool|list<string>>
     */
    public function attributes(): array
    {
        $attributes = [
            'langfuse.observation.type' => $this->type->value,
            'langfuse.trace.name' => $this->context->name,
        ];

        if ($this->input !== null) {
            $attributes['langfuse.observation.input'] = Json::encodeValue($this->input);
        }
        if ($this->output !== null) {
            $attributes['langfuse.observation.output'] = Json::encodeValue($this->output);
        }
        $metadata = $this->metadata;
        if ($this->files !== []) {
            $metadata['attachments'] = array_map(static fn(FileReference $file): array => $file->toArray(), $this->files);
        }
        if ($metadata !== []) {
            $attributes['langfuse.observation.metadata'] = Json::encodeObject($metadata);
        }
        if ($this->level !== null) {
            $attributes['langfuse.observation.level'] = $this->level->value;
        }
        if ($this->statusMessage !== null) {
            $attributes['langfuse.observation.status_message'] = $this->statusMessage;
        }
        if ($this->context->userId !== null) {
            $attributes['user.id'] = $this->context->userId;
        }
        if ($this->context->sessionId !== null) {
            $attributes['session.id'] = $this->context->sessionId;
        }
        if ($this->context->tags !== []) {
            $attributes['langfuse.trace.tags'] = $this->context->tags;
        }
        if ($this->context->public !== null) {
            $attributes['langfuse.trace.public'] = $this->context->public;
        }

        return $attributes + $this->experimentAttributes() + $this->tracer->globalAttributes();
    }

    /**
     * Langfuse v4 does not propagate experiment fields from the root span, so every
     * span of an experiment item carries them.
     *
     * @return array<string, string>
     */
    private function experimentAttributes(): array
    {
        $run = $this->context->experimentRun;
        if ($run === null || $this->context->experimentItemId === null || $this->context->experimentRootSpanId === null) {
            return [];
        }

        $attributes = [
            'langfuse.experiment.id' => $run->id,
            'langfuse.experiment.name' => $run->name,
            'langfuse.experiment.dataset.id' => $run->datasetId,
            'langfuse.experiment.item.id' => $this->context->experimentItemId,
            'langfuse.experiment.item.root_observation_id' => $this->context->experimentRootSpanId,
        ];
        if ($run->description !== null) {
            $attributes['langfuse.experiment.description'] = $run->description;
        }
        if ($run->metadata !== []) {
            $attributes['langfuse.experiment.metadata'] = Json::encodeObject($run->metadata);
        }
        if ($this->context->experimentExpectedOutput !== null) {
            $attributes['langfuse.experiment.item.expected_output'] = Json::encodeValue($this->context->experimentExpectedOutput);
        }

        return $attributes;
    }
}
