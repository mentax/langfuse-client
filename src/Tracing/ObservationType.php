<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Tracing;

/**
 * Values of the langfuse.observation.type span attribute.
 */
enum ObservationType: string
{
    case Span = 'span';
    case Generation = 'generation';
    case Event = 'event';
    case Agent = 'agent';
    case Tool = 'tool';
    case Chain = 'chain';
    case Retriever = 'retriever';
    case Evaluator = 'evaluator';
    case Guardrail = 'guardrail';
}
