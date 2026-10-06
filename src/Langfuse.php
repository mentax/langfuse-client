<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient;

use Mentax\LangfuseClient\Internal\HttpClient;
use Mentax\LangfuseClient\Prompt\CachedPromptProvider;
use Mentax\LangfuseClient\Prompt\PromptClient;
use Mentax\LangfuseClient\Tracing\Export\OtlpHttpExporter;
use Mentax\LangfuseClient\Tracing\Tracer;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Entry point that wires the library on top of your PSR-18 client and PSR-17 factories.
 */
final readonly class Langfuse
{
    private HttpClient $http;

    public function __construct(
        LangfuseConfig $config,
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
    ) {
        $this->http = new HttpClient($config, $httpClient, $requestFactory, $streamFactory);
    }

    /**
     * Direct access to the prompt API, without caching.
     */
    public function prompts(): PromptClient
    {
        return new PromptClient($this->http);
    }

    /**
     * Prompt provider for runtime use: serves cached prompts and survives Langfuse outages.
     */
    public function cachedPrompts(
        CacheItemPoolInterface $cache,
        int $ttlSeconds = CachedPromptProvider::DEFAULT_TTL_SECONDS,
        LoggerInterface $logger = new NullLogger(),
    ): CachedPromptProvider {
        return new CachedPromptProvider($this->prompts(), $cache, $ttlSeconds, $logger);
    }

    public function tracer(
        ?string $environment = null,
        ?string $release = null,
        LoggerInterface $logger = new NullLogger(),
        int $batchSize = Tracer::DEFAULT_BATCH_SIZE,
        bool $enabled = true,
    ): Tracer {
        return new Tracer(
            new OtlpHttpExporter($this->http),
            environment: $environment,
            release: $release,
            logger: $logger,
            batchSize: $batchSize,
            enabled: $enabled,
        );
    }
}
