<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Tests\Support;

use Mentax\LangfuseClient\Exception\TransportException;
use Mentax\LangfuseClient\Tracing\Export\SpanExporterInterface;
use Mentax\LangfuseClient\Tracing\Observation;

final class InMemoryExporter implements SpanExporterInterface
{
    /** @var list<list<Observation>> */
    public array $batches = [];

    public bool $fail = false;

    public function export(array $observations): void
    {
        if ($this->fail) {
            throw new TransportException('Langfuse unreachable');
        }
        $this->batches[] = $observations;
    }

    /**
     * @return list<Observation>
     */
    public function exported(): array
    {
        return array_merge(...$this->batches);
    }
}
