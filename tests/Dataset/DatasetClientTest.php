<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Tests\Dataset;

use Mentax\LangfuseClient\Dataset\DatasetClient;
use Mentax\LangfuseClient\Dataset\DatasetItem;
use Mentax\LangfuseClient\Media\MediaReference;
use Mentax\LangfuseClient\Tests\Support\Factory;
use Mentax\LangfuseClient\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class DatasetClientTest extends TestCase
{
    private FakeHttpClient $http;

    private DatasetClient $datasets;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->datasets = new DatasetClient(Factory::http($this->http));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function itemResponse(array $overrides = []): array
    {
        return array_replace([
            'id' => 'airbag-001',
            'datasetId' => 'ds-1',
            'datasetName' => 'damageaudit/airbag-photo',
            'status' => 'ACTIVE',
            'input' => ['documents_count' => 2, 'photos' => ['@@@langfuseMedia:type=image/jpeg|id=m1|source=bytes@@@']],
            'expectedOutput' => ['result' => true],
            'metadata' => ['source' => 'production'],
            'sourceTraceId' => null,
            'sourceObservationId' => null,
            'createdAt' => '2026-10-06T10:00:00.000Z',
            'updatedAt' => '2026-10-06T10:00:00.000Z',
            'mediaReferences' => [],
        ], $overrides);
    }

    public function testCreateDatasetUsesV2Endpoint(): void
    {
        $this->http->respondJson(['id' => 'ds-1', 'name' => 'damageaudit/airbag-photo', 'description' => null, 'metadata' => null]);

        $dataset = $this->datasets->createDataset('damageaudit/airbag-photo', metadata: ['owner' => 'rozwoj']);

        self::assertSame('/api/public/v2/datasets', $this->http->lastRequest()->getUri()->getPath());
        self::assertSame(['name' => 'damageaudit/airbag-photo', 'metadata' => ['owner' => 'rozwoj']], $this->http->lastRequestJson());
        self::assertSame('ds-1', $dataset->id);
    }

    public function testUpsertItemOmitsNullFields(): void
    {
        $this->http->respondJson(self::itemResponse());

        $item = $this->datasets->upsertItem('damageaudit/airbag-photo', ['documents_count' => 2], ['result' => true], id: 'airbag-001');

        self::assertSame(
            ['datasetName' => 'damageaudit/airbag-photo', 'input' => ['documents_count' => 2], 'expectedOutput' => ['result' => true], 'id' => 'airbag-001', 'status' => 'ACTIVE'],
            $this->http->lastRequestJson(),
        );
        self::assertSame('ds-1', $item->datasetId);
    }

    public function testItemsFollowsPagination(): void
    {
        $this->http
            ->respondJson(['data' => [self::itemResponse(['id' => 'a'])], 'meta' => ['page' => 1, 'limit' => 100, 'totalItems' => 2, 'totalPages' => 2]])
            ->respondJson(['data' => [self::itemResponse(['id' => 'b']), self::itemResponse(['id' => 'c', 'status' => 'ARCHIVED'])], 'meta' => ['page' => 2, 'limit' => 100, 'totalItems' => 3, 'totalPages' => 2]]);

        $ids = array_map(static fn(DatasetItem $item): string => $item->id, iterator_to_array($this->datasets->items('damageaudit/airbag-photo'), false));

        self::assertSame(['a', 'b'], $ids);
        self::assertSame('datasetName=damageaudit%2Fairbag-photo&page=2&limit=100', $this->http->lastRequest()->getUri()->getQuery());
    }

    public function testFindsMediaReferencesByPath(): void
    {
        $item = DatasetItem::fromArray(self::itemResponse());

        self::assertEquals(
            ['input.photos.0' => new MediaReference('m1', 'image/jpeg')],
            $item->mediaReferences(),
        );
    }
}
