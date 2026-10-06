<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Tests\Media;

use Mentax\LangfuseClient\Exception\LangfuseException;
use Mentax\LangfuseClient\Media\MediaClient;
use Mentax\LangfuseClient\Media\MediaReference;
use Mentax\LangfuseClient\Media\MediaTarget;
use Mentax\LangfuseClient\Tests\Support\DecodedJson;
use Mentax\LangfuseClient\Tests\Support\Factory;
use Mentax\LangfuseClient\Tests\Support\FakeHttpClient;
use Mentax\LangfuseClient\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class MediaClientTest extends TestCase
{
    private FakeHttpClient $http;

    private MediaClient $media;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->media = new MediaClient(Factory::http($this->http), new FrozenClock());
    }

    public function testUploadsToPresignedUrlAndReportsTheResult(): void
    {
        $this->http
            ->respondJson(['mediaId' => 'abc123', 'uploadUrl' => 'http://minio.test:9090/media/abc123?X-Amz-Signature=x'], 201)
            ->respond(200)
            ->respond(200);

        $reference = $this->media->upload('jpeg-bytes', 'image/jpeg', MediaTarget::datasetItem('ds-1', 'item-1'));

        self::assertSame('@@@langfuseMedia:type=image/jpeg|id=abc123|source=bytes@@@', (string) $reference);
        [$create, $put, $patch] = $this->http->requests;

        $hash = base64_encode(hash('sha256', 'jpeg-bytes', true));
        self::assertSame('/api/public/media', $create->getUri()->getPath());
        self::assertSame([
            'contentType' => 'image/jpeg',
            'contentLength' => 10,
            'sha256Hash' => $hash,
            'datasetId' => 'ds-1',
            'datasetItemId' => 'item-1',
            'field' => 'input',
        ], json_decode((string) $create->getBody(), true));

        self::assertSame('PUT', $put->getMethod());
        self::assertSame('minio.test', $put->getUri()->getHost());
        self::assertSame('', $put->getHeaderLine('Authorization'), 'Langfuse credentials must not go to the storage.');
        self::assertSame($hash, $put->getHeaderLine('x-amz-checksum-sha256'));
        self::assertSame('jpeg-bytes', (string) $put->getBody());

        self::assertSame('PATCH', $patch->getMethod());
        self::assertSame('/api/public/media/abc123', $patch->getUri()->getPath());
        $patchBody = new DecodedJson(json_decode((string) $patch->getBody(), true));
        self::assertSame(200, $patchBody->at('uploadHttpStatus')->value());
        self::assertNull($patchBody->at('uploadHttpError')->value());
    }

    public function testSkipsUploadWhenLangfuseAlreadyHasTheContent(): void
    {
        $this->http->respondJson(['mediaId' => 'abc123', 'uploadUrl' => null], 201);

        $this->media->upload('jpeg-bytes', 'image/jpeg', MediaTarget::trace(str_repeat('a', 32), 'input'));

        self::assertCount(1, $this->http->requests);
    }

    public function testReportsAndThrowsWhenStorageRejectsTheUpload(): void
    {
        $this->http
            ->respondJson(['mediaId' => 'abc123', 'uploadUrl' => 'http://minio.test:9090/x'], 201)
            ->respond(403, 'SignatureDoesNotMatch')
            ->respond(200);

        try {
            $this->media->upload('bytes', 'application/pdf', MediaTarget::trace(str_repeat('a', 32)));
            self::fail('Expected LangfuseException.');
        } catch (LangfuseException $e) {
            self::assertStringContainsString('HTTP 403', $e->getMessage());
        }

        $patch = new DecodedJson($this->http->lastRequestJson());
        self::assertSame(403, $patch->at('uploadHttpStatus')->value());
        self::assertSame('Storage answered HTTP 403', $patch->at('uploadHttpError')->value());
    }

    public function testDownloadsThroughThePresignedUrl(): void
    {
        $this->http
            ->respondJson([
                'mediaId' => 'abc123',
                'contentType' => 'image/jpeg',
                'contentLength' => 10,
                'uploadedAt' => '2026-10-06T10:00:00.000Z',
                'url' => 'http://minio.test:9090/media/abc123?sig',
                'urlExpiry' => '2026-10-06T11:00:00.000Z',
            ])
            ->respond(200, 'jpeg-bytes');

        self::assertSame('jpeg-bytes', $this->media->download(new MediaReference('abc123', 'image/jpeg')));
        self::assertSame('minio.test', $this->http->lastRequest()->getUri()->getHost());
    }

    public function testParsesReferences(): void
    {
        $reference = MediaReference::tryParse('@@@langfuseMedia:type=application/pdf|id=x-Y_1|source=bytes@@@');

        self::assertEquals(new MediaReference('x-Y_1', 'application/pdf'), $reference);
        self::assertNull(MediaReference::tryParse('see @@@langfuseMedia:type=image/png|id=x|source=bytes@@@'));
        self::assertNull(MediaReference::tryParse('@@@langfuseMedia:type=image/png|source=bytes@@@'));
    }
}
