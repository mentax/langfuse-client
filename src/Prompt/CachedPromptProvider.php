<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Prompt;

use Mentax\LangfuseClient\Exception\InvalidResponseException;
use Mentax\LangfuseClient\Exception\LangfuseException;
use Mentax\LangfuseClient\Internal\SystemClock;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Serves prompts from a PSR-6 cache and keeps the last known good version.
 *
 * - A cached entry younger than the TTL is returned without contacting Langfuse.
 * - An older entry triggers a fetch. If the fetch fails for any reason, including a
 *   404 after someone deleted the prompt, the old entry is returned and a warning is
 *   logged. Entries never expire from the cache on their own.
 * - With no cached entry, a failed fetch throws.
 * - A version-pinned prompt never changes in Langfuse, so it is fetched once.
 * - With a null TTL, cached entries are always served and Langfuse is called only on
 *   a cache miss. Keep them current with refreshAll() from a scheduled job.
 *
 * Use a persistent pool (e.g. Symfony FilesystemAdapter on shared storage) so that
 * a restart does not empty it.
 */
final readonly class CachedPromptProvider implements PromptProviderInterface
{
    public const DEFAULT_TTL_SECONDS = 600;

    private ClockInterface $clock;

    /**
     * @param int|null $ttlSeconds age after which an entry is refetched; null: never
     */
    public function __construct(
        private PromptProviderInterface $inner,
        private CacheItemPoolInterface $cache,
        private ?int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
        private LoggerInterface $logger = new NullLogger(),
        ?ClockInterface $clock = null,
        private string $keyPrefix = 'langfuse_prompt.',
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    public function get(string $name, ?string $label = null, ?int $version = null): TextPrompt|ChatPrompt
    {
        $cached = $this->read($name, $label, $version);
        if ($cached !== null && ($version !== null || $this->isFresh($cached['fetchedAt']))) {
            return $cached['prompt'];
        }

        try {
            return $this->fetchAndStore($name, $label, $version);
        } catch (LangfuseException $e) {
            if ($cached === null) {
                throw $e;
            }
            $this->logger->warning('Langfuse prompt refresh failed, serving the cached version.', [
                'prompt' => $name,
                'label' => $label,
                'cachedVersion' => $cached['prompt']->version,
                'cachedAt' => date(DATE_ATOM, $cached['fetchedAt']),
                'exception' => $e,
            ]);

            return $cached['prompt'];
        }
    }

    /**
     * Fetches from Langfuse and overwrites the cache, ignoring the TTL.
     * Unlike get(), a failure throws instead of falling back to the cached entry.
     *
     * @throws LangfuseException
     */
    public function refresh(string $name, ?string $label = null, ?int $version = null): TextPrompt|ChatPrompt
    {
        return $this->fetchAndStore($name, $label, $version);
    }

    /**
     * Refreshes every label of every listed prompt, for a scheduled job:
     *
     *     $report = $provider->refreshAll($langfuse->prompts()->list());
     *
     * A failure for one label is logged and reported; the others are still refreshed,
     * and the cache keeps serving the previous entry of the failed one.
     *
     * @param iterable<PromptMetadata> $prompts
     * @param list<string>|null $labels refresh only these labels; null: all labels of each prompt
     */
    public function refreshAll(iterable $prompts, ?array $labels = null): PromptRefreshReport
    {
        $refreshed = [];
        $failed = [];
        foreach ($prompts as $metadata) {
            $promptLabels = $labels === null ? $metadata->labels : array_intersect($metadata->labels, $labels);
            foreach ($promptLabels as $label) {
                try {
                    $prompt = $this->inner->get($metadata->name, $label);
                } catch (LangfuseException $e) {
                    $this->logger->error('Langfuse prompt refresh failed, the cached version stays in use.', ['prompt' => $metadata->name, 'label' => $label, 'exception' => $e]);
                    $failed[] = ['name' => $metadata->name, 'label' => $label, 'reason' => $e->getMessage()];

                    continue;
                }
                if (!$this->store($metadata->name, $label, null, $prompt)) {
                    $failed[] = ['name' => $metadata->name, 'label' => $label, 'reason' => 'cache write failed'];

                    continue;
                }
                $refreshed[] = ['name' => $metadata->name, 'label' => $label, 'version' => $prompt->version];
            }
        }

        return new PromptRefreshReport($refreshed, $failed);
    }

    private function fetchAndStore(string $name, ?string $label, ?int $version): TextPrompt|ChatPrompt
    {
        $prompt = $this->inner->get($name, $label, $version);
        $this->store($name, $label, $version, $prompt);

        return $prompt;
    }

    private function store(string $name, ?string $label, ?int $version, TextPrompt|ChatPrompt $prompt): bool
    {
        $item = $this->cache->getItem($this->key($name, $label, $version));
        $item->set(['fetchedAt' => $this->clock->now()->getTimestamp(), 'prompt' => $prompt->toArray()]);
        if (!$this->cache->save($item)) {
            $this->logger->warning('Could not write a Langfuse prompt to the cache.', ['prompt' => $name, 'label' => $label]);

            return false;
        }

        return true;
    }

    /**
     * @return array{fetchedAt: int, prompt: TextPrompt|ChatPrompt}|null
     */
    private function read(string $name, ?string $label, ?int $version): ?array
    {
        $item = $this->cache->getItem($this->key($name, $label, $version));
        if (!$item->isHit()) {
            return null;
        }

        $entry = $item->get();
        if (!is_array($entry) || !is_int($entry['fetchedAt'] ?? null) || !is_array($entry['prompt'] ?? null)) {
            return null;
        }

        try {
            return ['fetchedAt' => $entry['fetchedAt'], 'prompt' => Prompt::fromArray($entry['prompt'])];
        } catch (InvalidResponseException) {
            return null;
        }
    }

    private function isFresh(int $fetchedAt): bool
    {
        return $this->ttlSeconds === null || $this->clock->now()->getTimestamp() - $fetchedAt < $this->ttlSeconds;
    }

    /**
     * PSR-6 reserves {}()/\@: in keys, and prompt names may contain "/", so the
     * selector is hashed.
     */
    private function key(string $name, ?string $label, ?int $version): string
    {
        $selector = $version !== null ? 'v:' . $version : 'l:' . ($label ?? PromptClient::DEFAULT_LABEL);

        return $this->keyPrefix . hash('xxh128', $name . "\0" . $selector);
    }
}
