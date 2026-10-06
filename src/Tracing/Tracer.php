<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Tracing;

use DateTimeImmutable;
use InvalidArgumentException;
use Mentax\LangfuseClient\Internal\SystemClock;
use Mentax\LangfuseClient\Tracing\Export\SpanExporterInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Creates traces and buffers ended observations until flush().
 *
 * Tracing never breaks the traced code: flush() and shutdown() log export failures
 * and drop the batch instead of throwing. Call flush() at the end of each unit of
 * work, e.g. on kernel.terminate or after each Messenger message, and shutdown()
 * when the process ends.
 */
final class Tracer
{
    public const DEFAULT_BATCH_SIZE = 100;

    private const ENVIRONMENT_PATTERN = '/^[a-z0-9_-]{1,40}$/';

    private readonly ClockInterface $clock;

    /** @var array<string, Observation> observations started but not ended, by span ID */
    private array $open = [];

    /** @var list<Observation> */
    private array $pending = [];

    /**
     * @param string|null $environment e.g. "production"; lowercase letters, digits, "-" and "_", at most 40
     *                                 characters, not starting with "langfuse" (Langfuse rules)
     * @param int         $batchSize   pending observations that trigger an automatic flush
     */
    public function __construct(
        private readonly SpanExporterInterface $exporter,
        private readonly ?string $environment = null,
        private readonly ?string $release = null,
        private readonly ?string $version = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?ClockInterface $clock = null,
        private readonly int $batchSize = self::DEFAULT_BATCH_SIZE,
        private readonly bool $enabled = true,
    ) {
        if ($environment !== null && (preg_match(self::ENVIRONMENT_PATTERN, $environment) !== 1 || str_starts_with($environment, 'langfuse'))) {
            throw new InvalidArgumentException(sprintf(
                'Invalid Langfuse environment "%s": use 1-40 lowercase letters, digits, "-" or "_", not starting with "langfuse".',
                $environment,
            ));
        }
        if ($batchSize < 1) {
            throw new InvalidArgumentException('Batch size must be at least 1.');
        }
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * Starts a trace, represented by its root span.
     *
     * @param array<string, mixed> $metadata
     * @param list<string> $tags
     * @param string|null $traceId 32 lowercase hex characters; see traceIdFromSeed()
     */
    public function startTrace(
        string $name,
        mixed $input = null,
        ?string $userId = null,
        ?string $sessionId = null,
        array $tags = [],
        array $metadata = [],
        ?string $traceId = null,
    ): Trace {
        if ($traceId !== null && preg_match('/^[0-9a-f]{32}$/', $traceId) !== 1) {
            throw new InvalidArgumentException(sprintf('Trace ID must be 32 lowercase hex characters, "%s" given.', $traceId));
        }

        $context = new TraceContext($traceId ?? bin2hex(random_bytes(16)), $name);
        $trace = new Trace($this, $context, null, $name, ObservationType::Span, $input, $metadata);

        return $trace->setUserId($userId)->setSessionId($sessionId)->addTags(...$tags);
    }

    /**
     * Derives a stable trace ID from your own identifier (e.g. an order or audit ID),
     * so the trace can be found or scored later without storing its ID.
     */
    public static function traceIdFromSeed(string $seed): string
    {
        return md5($seed);
    }

    /**
     * Exports all ended observations. Never throws.
     */
    public function flush(): void
    {
        if ($this->pending === []) {
            return;
        }

        $batch = $this->pending;
        $this->pending = [];

        if (!$this->enabled) {
            return;
        }

        try {
            $this->exporter->export($batch);
        } catch (Throwable $e) {
            $this->logger->error('Langfuse trace export failed; {count} observations dropped.', [
                'count' => count($batch),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Ends observations still open (level WARNING) and flushes. Never throws.
     */
    public function shutdown(): void
    {
        foreach ($this->open as $observation) {
            $observation->setLevel(ObservationLevel::Warning, 'Observation was not ended before the tracer shut down.');
            $observation->end();
        }
        $this->flush();
    }

    /**
     * @internal
     */
    public function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }

    /**
     * @internal
     */
    public function newSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * @internal
     */
    public function opened(Observation $observation): void
    {
        $this->open[$observation->id()] = $observation;
    }

    /**
     * @internal
     */
    public function ended(Observation $observation): void
    {
        unset($this->open[$observation->id()]);
        $this->pending[] = $observation;

        if (count($this->pending) >= $this->batchSize) {
            $this->flush();
        }
    }

    /**
     * Attributes written on every span of this tracer.
     *
     * @internal
     *
     * @return array<string, string>
     */
    public function globalAttributes(): array
    {
        return array_filter(
            [
                'langfuse.environment' => $this->environment,
                'langfuse.release' => $this->release,
                'langfuse.version' => $this->version,
            ],
            static fn(?string $value): bool => $value !== null,
        );
    }
}
