<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Tests\Prompt;

use Mentax\LangfuseClient\Exception\NotFoundException;
use Mentax\LangfuseClient\Exception\TransportException;
use Mentax\LangfuseClient\Prompt\CachedPromptProvider;
use Mentax\LangfuseClient\Prompt\PromptClient;
use Mentax\LangfuseClient\Prompt\PromptMetadata;
use Mentax\LangfuseClient\Prompt\PromptType;
use Mentax\LangfuseClient\Tests\Support\Factory;
use Mentax\LangfuseClient\Tests\Support\FakeHttpClient;
use Mentax\LangfuseClient\Tests\Support\FrozenClock;
use Mentax\LangfuseClient\Tests\Support\InMemoryLogger;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

final class CachedPromptProviderTest extends TestCase
{
    private FakeHttpClient $http;

    private FrozenClock $clock;

    private InMemoryLogger $logger;

    private CacheItemPoolInterface $cache;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->clock = new FrozenClock();
        $this->logger = new InMemoryLogger();
        $this->cache = new ArrayAdapter();
    }

    private function provider(?CacheItemPoolInterface $cache = null, ?int $ttlSeconds = 600): CachedPromptProvider
    {
        return new CachedPromptProvider(
            new PromptClient(Factory::http($this->http)),
            $cache ?? $this->cache,
            ttlSeconds: $ttlSeconds,
            logger: $this->logger,
            clock: $this->clock,
        );
    }

    public function testServesFreshEntryWithoutContactingLangfuse(): void
    {
        $this->http->respondJson(Factory::textPromptResponse());
        $provider = $this->provider();

        $provider->get('p');
        $this->clock->advance(599);
        $prompt = $provider->get('p');

        self::assertCount(1, $this->http->requests);
        self::assertSame(3, $prompt->version);
    }

    public function testRefetchesAfterTtl(): void
    {
        $this->http
            ->respondJson(Factory::textPromptResponse(['version' => 3]))
            ->respondJson(Factory::textPromptResponse(['version' => 4]));
        $provider = $this->provider();

        $provider->get('p');
        $this->clock->advance(600);

        self::assertSame(4, $provider->get('p')->version);
        self::assertCount(2, $this->http->requests);
    }

    public function testServesStaleEntryWhenLangfuseIsDown(): void
    {
        $this->http->respondJson(Factory::textPromptResponse())->failWithNetworkError();
        $provider = $this->provider();

        $provider->get('p');
        $this->clock->advance(3600);

        self::assertSame(3, $provider->get('p')->version);
        self::assertCount(1, $this->logger->messages('warning'));
    }

    public function testServesStaleEntryWhenPromptWasDeleted(): void
    {
        $this->http->respondJson(Factory::textPromptResponse())->respond(404, '{}');
        $provider = $this->provider();

        $provider->get('p');
        $this->clock->advance(3600);

        self::assertSame(3, $provider->get('p')->version);
    }

    public function testThrowsWithoutCachedEntry(): void
    {
        $this->http->failWithNetworkError();

        $this->expectException(TransportException::class);
        $this->provider()->get('p');
    }

    public function testRefreshIgnoresTtlAndDoesNotFallBack(): void
    {
        $this->http
            ->respondJson(Factory::textPromptResponse(['version' => 3]))
            ->respondJson(Factory::textPromptResponse(['version' => 4]))
            ->respond(404, '{}');
        $provider = $this->provider();

        $provider->get('p');
        self::assertSame(4, $provider->refresh('p')->version);
        self::assertSame(4, $provider->get('p')->version);

        $this->expectException(NotFoundException::class);
        $provider->refresh('p');
    }

    public function testNullTtlNeverRefetches(): void
    {
        $this->http->respondJson(Factory::textPromptResponse());
        $provider = $this->provider(ttlSeconds: null);

        $provider->get('p');
        $this->clock->advance(86400 * 365);
        $provider->get('p');

        self::assertCount(1, $this->http->requests);
    }

    public function testRefreshAllRefreshesEveryLabelAndContinuesAfterFailure(): void
    {
        $this->http
            ->respondJson(Factory::textPromptResponse(['version' => 5]))
            ->respond(503, 'maintenance')
            ->respondJson(Factory::textPromptResponse(['version' => 2]));
        $provider = $this->provider(ttlSeconds: null);

        $report = $provider->refreshAll([
            new PromptMetadata('a', PromptType::Text, [4, 5], ['production', 'latest']),
            new PromptMetadata('b', PromptType::Text, [2], ['production']),
        ]);

        self::assertSame([['name' => 'a', 'label' => 'production', 'version' => 5], ['name' => 'b', 'label' => 'production', 'version' => 2]], $report->refreshed);
        self::assertSame('a', $report->failed[0]['name']);
        self::assertSame('latest', $report->failed[0]['label']);
        self::assertFalse($report->isComplete());
        self::assertCount(1, $this->logger->messages('error'));

        self::assertSame(5, $provider->get('a')->version);
        self::assertSame(2, $provider->get('b', 'production')->version);
        self::assertCount(3, $this->http->requests, 'Refreshed entries are served from the cache.');
    }

    public function testRefreshAllCanBeLimitedToLabels(): void
    {
        $this->http->respondJson(Factory::textPromptResponse());

        $report = $this->provider()->refreshAll(
            [new PromptMetadata('a', PromptType::Text, [3], ['production', 'latest', 'staging'])],
            labels: ['production', 'canary'],
        );

        self::assertSame([['name' => 'a', 'label' => 'production', 'version' => 3]], $report->refreshed);
        self::assertTrue($report->isComplete());
        self::assertSame('label=production', $this->http->lastRequest()->getUri()->getQuery());
    }

    public function testVersionPinnedPromptIsFetchedOnce(): void
    {
        $this->http->respondJson(Factory::textPromptResponse(['version' => 2]));
        $provider = $this->provider();

        $provider->get('p', version: 2);
        $this->clock->advance(86400);
        $provider->get('p', version: 2);

        self::assertCount(1, $this->http->requests);
    }

    public function testLabelsAndVersionsAreCachedSeparately(): void
    {
        $this->http
            ->respondJson(Factory::textPromptResponse(['version' => 3]))
            ->respondJson(Factory::textPromptResponse(['version' => 4]));
        $provider = $this->provider();

        self::assertSame(3, $provider->get('p')->version);
        self::assertSame(4, $provider->get('p', 'staging')->version);
    }

    public function testPersistsAcrossInstancesWithFilesystemCache(): void
    {
        $directory = sys_get_temp_dir() . '/mentax-langfuse-client-' . bin2hex(random_bytes(4));
        try {
            $this->http->respondJson(Factory::textPromptResponse())->failWithNetworkError();

            $this->provider(new FilesystemAdapter('prompts', 0, $directory))->get('damageaudit/airbag-photo');
            $this->clock->advance(3600);
            $prompt = $this->provider(new FilesystemAdapter('prompts', 0, $directory))->get('damageaudit/airbag-photo');

            self::assertSame('Check {{documents_count}} photos for airbags.', $prompt->toArray()['prompt']);
        } finally {
            self::removeDirectory($directory);
        }
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
}
