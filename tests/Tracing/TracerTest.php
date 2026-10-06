<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Tests\Tracing;

use InvalidArgumentException;
use Mentax\LangfuseClient\Prompt\TextPrompt;
use Mentax\LangfuseClient\Tests\Support\FrozenClock;
use Mentax\LangfuseClient\Tests\Support\InMemoryExporter;
use Mentax\LangfuseClient\Tests\Support\InMemoryLogger;
use Mentax\LangfuseClient\Tracing\ObservationLevel;
use Mentax\LangfuseClient\Tracing\Tracer;
use Mentax\LangfuseClient\Tracing\Usage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TracerTest extends TestCase
{
    private InMemoryExporter $exporter;

    private InMemoryLogger $logger;

    protected function setUp(): void
    {
        $this->exporter = new InMemoryExporter();
        $this->logger = new InMemoryLogger();
    }

    private function tracer(int $batchSize = 100): Tracer
    {
        return new Tracer(
            $this->exporter,
            environment: 'production',
            release: '1.4.0',
            logger: $this->logger,
            clock: new FrozenClock(),
            batchSize: $batchSize,
        );
    }

    public function testExportsGenerationWithPromptLinkUsageAndModel(): void
    {
        $tracer = $this->tracer();
        $prompt = new TextPrompt('damageaudit/airbag-photo', 3, 'x');

        $trace = $tracer->startTrace('audit-rule', input: ['claim' => 'C-1'], userId: 'user-7', tags: ['damageaudit']);
        $generation = $trace->startGeneration('airbag', 'gemini-2.5-flash-lite', 'prompt text', ['temperature' => 0], $prompt);
        $generation->end(['result' => true], new Usage(input: 120, output: 30, total: 150));
        $trace->end(['passed' => true]);
        $tracer->flush();

        [$exportedGeneration, $exportedTrace] = $this->exporter->exported();
        self::assertSame($trace->id(), $exportedGeneration->parentId());
        self::assertNull($exportedTrace->parentId());
        self::assertSame($trace->traceId(), $exportedGeneration->traceId());

        self::assertSame([
            'langfuse.observation.type' => 'generation',
            'langfuse.trace.name' => 'audit-rule',
            'langfuse.observation.input' => 'prompt text',
            'langfuse.observation.output' => '{"result":true}',
            'user.id' => 'user-7',
            'langfuse.trace.tags' => ['damageaudit'],
            'langfuse.environment' => 'production',
            'langfuse.release' => '1.4.0',
            'langfuse.observation.model.name' => 'gemini-2.5-flash-lite',
            'langfuse.observation.model.parameters' => '{"temperature":0}',
            'langfuse.observation.usage_details' => '{"input":120,"output":30,"total":150}',
            'langfuse.observation.prompt.name' => 'damageaudit/airbag-photo',
            'langfuse.observation.prompt.version' => 3,
        ], $exportedGeneration->attributes());

        self::assertSame('{"claim":"C-1"}', $exportedTrace->attributes()['langfuse.observation.input']);
        self::assertSame('{"passed":true}', $exportedTrace->attributes()['langfuse.observation.output']);
    }

    public function testTraceLevelFieldsSetLaterReachEverySpan(): void
    {
        $tracer = $this->tracer();
        $trace = $tracer->startTrace('t');
        $span = $trace->startSpan('step');
        $trace->setSessionId('session-1')->addTags('a', ' ', 'a', 'b');
        $span->end();
        $trace->end();
        $tracer->flush();

        foreach ($this->exporter->exported() as $observation) {
            self::assertSame('session-1', $observation->attributes()['session.id']);
            self::assertSame(['a', 'b'], $observation->attributes()['langfuse.trace.tags']);
        }
    }

    public function testFailMarksErrorAndEnds(): void
    {
        $tracer = $this->tracer();
        $generation = $tracer->startTrace('t')->startGeneration('call', 'gemini-2.5-flash');

        $generation->fail(new RuntimeException('RESOURCE_EXHAUSTED'));

        self::assertTrue($generation->isEnded());
        self::assertSame(2, $generation->statusCode());
        self::assertSame('ERROR', $generation->attributes()['langfuse.observation.level']);
        self::assertSame('RuntimeException: RESOURCE_EXHAUSTED', $generation->attributes()['langfuse.observation.status_message']);
    }

    public function testFlushNeverThrowsAndLogsDroppedBatch(): void
    {
        $tracer = $this->tracer();
        $tracer->startTrace('t')->end();
        $this->exporter->fail = true;

        $tracer->flush();

        self::assertSame(['Langfuse trace export failed; {count} observations dropped.'], $this->logger->messages('error'));
        $this->exporter->fail = false;
        $tracer->flush();
        self::assertSame([], $this->exporter->batches, 'A failed batch must not be retried or kept in memory.');
    }

    public function testFlushesAutomaticallyAtBatchSize(): void
    {
        $tracer = $this->tracer(batchSize: 2);
        $trace = $tracer->startTrace('t');

        $trace->startSpan('a')->end();
        self::assertCount(0, $this->exporter->batches);
        $trace->startSpan('b')->end();
        self::assertCount(1, $this->exporter->batches);
    }

    public function testShutdownEndsOpenObservationsWithWarning(): void
    {
        $tracer = $this->tracer();
        $trace = $tracer->startTrace('t');
        $trace->startGeneration('never-ended', 'gemini-2.5-flash');

        $tracer->shutdown();

        $exported = $this->exporter->exported();
        self::assertCount(2, $exported);
        foreach ($exported as $observation) {
            self::assertTrue($observation->isEnded());
            self::assertSame(ObservationLevel::Warning->value, $observation->attributes()['langfuse.observation.level']);
        }
    }

    public function testEndIsIdempotent(): void
    {
        $tracer = $this->tracer();
        $trace = $tracer->startTrace('t');
        $trace->end('first');
        $trace->end('second');
        $tracer->flush();

        self::assertCount(1, $this->exporter->exported());
        self::assertSame('first', $trace->attributes()['langfuse.observation.output']);
    }

    public function testDisabledTracerExportsNothing(): void
    {
        $tracer = new Tracer($this->exporter, enabled: false);
        $tracer->startTrace('t')->end();
        $tracer->flush();

        self::assertSame([], $this->exporter->batches);
    }

    public function testCallerSuppliedTraceId(): void
    {
        $id = Tracer::traceIdFromSeed('audit-42');

        self::assertSame($id, $this->tracer()->startTrace('t', traceId: $id)->traceId());
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
    }

    public function testRejectsMalformedTraceId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->tracer()->startTrace('t', traceId: '6f1c2d3e-0000-4000-8000-000000000000');
    }

    #[DataProvider('invalidEnvironments')]
    public function testRejectsEnvironmentsLangfuseWouldRename(string $environment): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Tracer($this->exporter, environment: $environment);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidEnvironments(): iterable
    {
        yield 'uppercase' => ['Production'];
        yield 'dot' => ['prod.eu'];
        yield 'too long' => [str_repeat('a', 41)];
        yield 'langfuse prefix' => ['langfuse-prod'];
    }
}
