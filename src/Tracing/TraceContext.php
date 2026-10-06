<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Tracing;

/**
 * Trace-level fields shared by every observation of one trace.
 *
 * Langfuse v4 derives a trace from its spans, so these fields are written on every
 * exported span, not only on the root.
 *
 * @internal
 */
final class TraceContext
{
    public ?string $userId = null;

    public ?string $sessionId = null;

    /** @var list<string> */
    public array $tags = [];

    public ?bool $public = null;

    public ?ExperimentRun $experimentRun = null;

    public ?string $experimentItemId = null;

    public mixed $experimentExpectedOutput = null;

    public ?string $experimentRootSpanId = null;

    public function __construct(
        public readonly string $traceId,
        public readonly string $name,
    ) {}
}
