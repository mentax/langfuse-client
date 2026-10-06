<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Tests\Tracing;

use Mentax\LangfuseClient\Dataset\DatasetItem;
use Mentax\LangfuseClient\Tests\Support\FrozenClock;
use Mentax\LangfuseClient\Tests\Support\InMemoryExporter;
use Mentax\LangfuseClient\Tracing\ExperimentRun;
use Mentax\LangfuseClient\Tracing\Tracer;
use PHPUnit\Framework\TestCase;

final class ExperimentTraceTest extends TestCase
{
    public function testEverySpanOfAnExperimentItemCarriesTheRunAttributes(): void
    {
        $exporter = new InMemoryExporter();
        $tracer = new Tracer($exporter, clock: new FrozenClock());
        $run = new ExperimentRun('ds-1', 'prompt v7 / gemini-3.5-flash', metadata: ['promptVersion' => 7]);
        $item = new DatasetItem('airbag-001', 'ds-1', 'damageaudit/airbag-photo', ['documents_count' => 2], ['result' => true], null);

        $trace = $tracer->startExperimentTrace($run, $item);
        $trace->startGeneration('call', 'gemini-3.5-flash')->end(['result' => false]);
        $trace->end(['result' => false]);
        $tracer->flush();

        $exported = $exporter->exported();
        self::assertCount(2, $exported);
        foreach ($exported as $observation) {
            $attributes = $observation->attributes();
            self::assertSame($run->id, $attributes['langfuse.experiment.id']);
            self::assertSame('prompt v7 / gemini-3.5-flash', $attributes['langfuse.experiment.name']);
            self::assertSame('ds-1', $attributes['langfuse.experiment.dataset.id']);
            self::assertSame('airbag-001', $attributes['langfuse.experiment.item.id']);
            self::assertSame($trace->id(), $attributes['langfuse.experiment.item.root_observation_id']);
            self::assertSame('{"result":true}', $attributes['langfuse.experiment.item.expected_output']);
            self::assertSame('{"promptVersion":7}', $attributes['langfuse.experiment.metadata']);
        }
        self::assertSame('{"documents_count":2}', $trace->attributes()['langfuse.observation.input']);
    }

    public function testRunIdIsStablePerDatasetAndName(): void
    {
        self::assertSame((new ExperimentRun('ds-1', 'a'))->id, (new ExperimentRun('ds-1', 'a'))->id);
        self::assertNotSame((new ExperimentRun('ds-1', 'a'))->id, (new ExperimentRun('ds-2', 'a'))->id);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (new ExperimentRun('ds-1', 'a'))->id);
    }

    public function testRegularTracesHaveNoExperimentAttributes(): void
    {
        $tracer = new Tracer(new InMemoryExporter(), clock: new FrozenClock());

        self::assertArrayNotHasKey('langfuse.experiment.id', $tracer->startTrace('t')->attributes());
    }
}
