<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Tests\Tracing;

use Mentax\LangfuseClient\Tests\Support\DecodedJson;
use Mentax\LangfuseClient\Tests\Support\Factory;
use Mentax\LangfuseClient\Tests\Support\FakeHttpClient;
use Mentax\LangfuseClient\Tests\Support\FrozenClock;
use Mentax\LangfuseClient\Tests\Support\InMemoryExporter;
use Mentax\LangfuseClient\Tracing\Export\OtlpEncoder;
use Mentax\LangfuseClient\Tracing\Export\OtlpHttpExporter;
use Mentax\LangfuseClient\Tracing\Tracer;
use Mentax\LangfuseClient\Tracing\Usage;
use Mentax\LangfuseClient\Version;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OtlpHttpExporterTest extends TestCase
{
    public function testPostsOtlpJsonInTheShapeLangfuseIngests(): void
    {
        $http = (new FakeHttpClient())->respondJson([]);
        $tracer = new Tracer(new OtlpHttpExporter(Factory::http($http)), clock: new FrozenClock());

        $trace = $tracer->startTrace('audit');
        $generation = $trace->startGeneration('call', 'gemini-2.5-flash');
        $generation->fail(new RuntimeException('boom'));
        $trace->end();
        $tracer->flush();

        $request = $http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/public/otel/v1/traces', $request->getUri()->getPath());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('4', $request->getHeaderLine('x-langfuse-ingestion-version'));
        self::assertSame(Version::VERSION, $request->getHeaderLine('x-langfuse-sdk-version'));

        $scopeSpans = (new DecodedJson($http->lastRequestJson()))->at('resourceSpans', 0, 'scopeSpans', 0);
        self::assertSame(OtlpEncoder::SCOPE_NAME, $scopeSpans->at('scope', 'name')->string());
        self::assertStringStartsWith('langfuse-sdk', OtlpEncoder::SCOPE_NAME);

        $generationSpan = $scopeSpans->at('spans', 0);
        $rootSpan = $scopeSpans->at('spans', 1);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $rootSpan->at('traceId')->string());
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $rootSpan->at('spanId')->string());
        self::assertFalse($rootSpan->has('parentSpanId'));
        self::assertSame($rootSpan->at('spanId')->string(), $generationSpan->at('parentSpanId')->string());
        self::assertSame('1791288000123456000', $rootSpan->at('startTimeUnixNano')->string());
        self::assertSame(['code' => 2, 'message' => 'RuntimeException: boom'], $generationSpan->at('status')->array());
        self::assertContains(
            ['key' => 'langfuse.observation.model.name', 'value' => ['stringValue' => 'gemini-2.5-flash']],
            $generationSpan->at('attributes')->array(),
        );
    }

    public function testInvalidUtf8DegradesInsteadOfDroppingTheBatch(): void
    {
        $http = (new FakeHttpClient())->respondJson([]);
        $tracer = new Tracer(new OtlpHttpExporter(Factory::http($http)), clock: new FrozenClock());

        $trace = $tracer->startTrace("audit-\xE9", input: "zg\xB3oszenie", tags: ["szkoda-\xB9"]);
        $trace->fail(new RuntimeException("b\xB3\xB9d"));
        $tracer->flush();

        $span = (new DecodedJson($http->lastRequestJson()))->at('resourceSpans', 0, 'scopeSpans', 0, 'spans', 0);
        self::assertSame("audit-\u{FFFD}", $span->at('name')->string());
        self::assertSame("RuntimeException: b\u{FFFD}\u{FFFD}d", $span->at('status', 'message')->string());
        self::assertContains(
            ['key' => 'langfuse.observation.input', 'value' => ['stringValue' => "zg\u{FFFD}oszenie"]],
            $span->at('attributes')->array(),
        );
        self::assertContains(
            ['key' => 'langfuse.trace.tags', 'value' => ['arrayValue' => ['values' => [['stringValue' => "szkoda-\u{FFFD}"]]]]],
            $span->at('attributes')->array(),
        );
    }

    public function testEncodesAttributeValueTypes(): void
    {
        $tracer = new Tracer(new InMemoryExporter(), clock: new FrozenClock());
        $trace = $tracer->startTrace('t', tags: ['a']);
        $trace->setPublic(true);
        $generation = $trace->startGeneration('g', 'm');
        $generation->linkPrompt('p', 7)->end(usage: new Usage(input: 1));
        $trace->end();

        $span = OtlpEncoder::encode([$generation])['resourceSpans'][0]['scopeSpans'][0]['spans'][0];
        $attributes = array_column($span['attributes'], 'value', 'key');

        self::assertSame(['intValue' => '7'], $attributes['langfuse.observation.prompt.version']);
        self::assertSame(['boolValue' => true], $attributes['langfuse.trace.public']);
        self::assertSame(['arrayValue' => ['values' => [['stringValue' => 'a']]]], $attributes['langfuse.trace.tags']);
        self::assertSame(['stringValue' => '{"input":1}'], $attributes['langfuse.observation.usage_details']);
    }
}
