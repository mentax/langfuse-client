<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Tests\Tracing;

use InvalidArgumentException;
use Mentax\LangfuseClient\Tests\Support\FrozenClock;
use Mentax\LangfuseClient\Tests\Support\InMemoryExporter;
use Mentax\LangfuseClient\Tracing\FileReference;
use Mentax\LangfuseClient\Tracing\Tracer;
use PHPUnit\Framework\TestCase;

final class FileReferenceTest extends TestCase
{
    public function testAttachedFilesAreRecordedInMetadataWithoutContent(): void
    {
        $tracer = new Tracer(new InMemoryExporter(), clock: new FrozenClock());
        $generation = $tracer->startTrace('t')->startGeneration('g', 'm', metadata: ['rule' => 'airbag']);

        $generation->attachFile(
            new FileReference('doc-17', 'IMG_0001.jpg', 'image/jpeg', 'https://audit.example/document/17', str_repeat('a', 64), 2048),
            new FileReference('doc-18', 'statement.pdf'),
        );

        self::assertSame(
            '{"rule":"airbag","attachments":[{"id":"doc-17","name":"IMG_0001.jpg","mimeType":"image/jpeg","url":"https://audit.example/document/17","sha256":"'
            . str_repeat('a', 64) . '","size":2048},{"id":"doc-18","name":"statement.pdf"}]}',
            $generation->attributes()['langfuse.observation.metadata'],
        );
    }

    public function testFromLocalFileComputesHashAndSize(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'lfc');
        self::assertIsString($path);
        try {
            file_put_contents($path, 'hello');

            $file = FileReference::fromLocalFile($path, 'doc-1', name: 'hello.txt');

            self::assertSame(hash('sha256', 'hello'), $file->sha256);
            self::assertSame(5, $file->size);
            self::assertSame('hello.txt', $file->name);
        } finally {
            unlink($path);
        }
    }

    public function testMarkdownRendersImagesInlineAndOtherFilesAsLinks(): void
    {
        self::assertSame('![a.jpg](https://x/1)', (new FileReference('1', 'a.jpg', 'image/jpeg', 'https://x/1'))->toMarkdown());
        self::assertSame('[b.pdf](https://x/2)', (new FileReference('2', 'b.pdf', 'application/pdf', 'https://x/2'))->toMarkdown());
        self::assertSame('c.pdf (3)', (new FileReference('3', 'c.pdf'))->toMarkdown());
    }

    public function testRejectsMalformedHash(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FileReference('1', 'a', sha256: 'ABC');
    }
}
