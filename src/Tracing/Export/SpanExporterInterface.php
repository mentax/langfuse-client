<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Tracing\Export;

use Mentax\LangfuseClient\Exception\LangfuseException;
use Mentax\LangfuseClient\Tracing\Observation;

interface SpanExporterInterface
{
    /**
     * @param non-empty-list<Observation> $observations ended observations
     *
     * @throws LangfuseException
     */
    public function export(array $observations): void;
}
