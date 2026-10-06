<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Tests\Tracing;

use InvalidArgumentException;
use Mentax\LangfuseClient\Tracing\Usage;
use PHPUnit\Framework\TestCase;

final class UsageTest extends TestCase
{
    public function testMapsGeminiUsageMetadataWithThinkingAsOutput(): void
    {
        $usage = Usage::fromGeminiUsageMetadata([
            'promptTokenCount' => 1200,
            'candidatesTokenCount' => 80,
            'thoughtsTokenCount' => 512,
            'cachedContentTokenCount' => 1000,
            'totalTokenCount' => 1792,
        ]);

        self::assertSame([
            'input' => 1200,
            'output' => 592,
            'total' => 1792,
            'output_reasoning_tokens' => 512,
            'input_cached_tokens' => 1000,
        ], $usage->toArray());
    }

    public function testOmitsAbsentCounts(): void
    {
        self::assertSame(['input' => 5, 'output' => 0], Usage::fromGeminiUsageMetadata(['promptTokenCount' => 5])->toArray());
    }

    public function testRejectsNegativeCounts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Usage(input: -1);
    }
}
