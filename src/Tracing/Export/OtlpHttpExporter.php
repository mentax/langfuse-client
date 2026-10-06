<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Tracing\Export;

use Mentax\LangfuseClient\Internal\HttpClient;
use Mentax\LangfuseClient\Version;

/**
 * Sends observations to Langfuse's OTLP endpoint as OTLP/HTTP JSON.
 *
 * Langfuse v4 accepts traces only over OTLP; the legacy /api/public/ingestion
 * endpoint rejects trace and observation events by default.
 */
final readonly class OtlpHttpExporter implements SpanExporterInterface
{
    public const PATH = '/api/public/otel/v1/traces';

    public function __construct(
        private HttpClient $http,
    ) {}

    public function export(array $observations): void
    {
        $this->http->post(self::PATH, OtlpEncoder::encode($observations), [
            'x-langfuse-ingestion-version' => '4',
            'x-langfuse-sdk-name' => 'mentax-langfuse-client-php',
            'x-langfuse-sdk-version' => Version::VERSION,
        ]);
    }
}
