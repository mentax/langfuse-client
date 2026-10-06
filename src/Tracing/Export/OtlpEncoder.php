<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Tracing\Export;

use DateTimeImmutable;
use Mentax\LangfuseClient\Tracing\Observation;
use Mentax\LangfuseClient\Version;

/**
 * Builds an OTLP/HTTP JSON ExportTraceServiceRequest from observations.
 *
 * Langfuse-specific choices:
 * - IDs are lowercase hex strings (Langfuse stores them verbatim; base64 is not decoded).
 * - Objects travel as JSON strings: Langfuse does not decode kvlistValue.
 * - The scope name starts with "langfuse-sdk"; for any other scope Langfuse copies
 *   every span attribute into the observation metadata as well.
 *
 * @internal
 *
 * @phpstan-type OtlpAttribute array{key: string, value: array<string, mixed>}
 * @phpstan-type OtlpSpan array{
 *     traceId: string,
 *     spanId: string,
 *     parentSpanId?: string,
 *     name: string,
 *     kind: int,
 *     startTimeUnixNano: string,
 *     endTimeUnixNano: string,
 *     attributes: list<OtlpAttribute>,
 *     status?: array{code?: int, message?: string}
 * }
 * @phpstan-type OtlpRequest array{resourceSpans: non-empty-list<array{
 *     resource: array{attributes: list<OtlpAttribute>},
 *     scopeSpans: non-empty-list<array{scope: array{name: string, version: string}, spans: non-empty-list<OtlpSpan>}>
 * }>}
 */
final class OtlpEncoder
{
    public const SCOPE_NAME = 'langfuse-sdk-php-mentax';

    private const SPAN_KIND_INTERNAL = 1;

    /**
     * @param non-empty-list<Observation> $observations
     *
     * @return OtlpRequest
     */
    public static function encode(array $observations): array
    {
        return [
            'resourceSpans' => [[
                'resource' => [
                    'attributes' => self::attributes([
                        'telemetry.sdk.language' => 'php',
                        'telemetry.sdk.name' => 'mentax/langfuse-client',
                        'telemetry.sdk.version' => Version::VERSION,
                    ]),
                ],
                'scopeSpans' => [[
                    'scope' => ['name' => self::SCOPE_NAME, 'version' => Version::VERSION],
                    'spans' => array_map(self::span(...), $observations),
                ]],
            ]],
        ];
    }

    /**
     * @return OtlpSpan
     */
    private static function span(Observation $observation): array
    {
        $end = $observation->endTime() ?? $observation->startTime();
        $span = [
            'traceId' => $observation->traceId(),
            'spanId' => $observation->id(),
            'name' => $observation->name(),
            'kind' => self::SPAN_KIND_INTERNAL,
            'startTimeUnixNano' => self::nanoseconds($observation->startTime()),
            'endTimeUnixNano' => self::nanoseconds($end),
            'attributes' => self::attributes($observation->attributes()),
        ];

        if ($observation->parentId() !== null) {
            $span['parentSpanId'] = $observation->parentId();
        }
        if ($observation->statusCode() !== null) {
            $span['status'] = array_filter(
                ['code' => $observation->statusCode(), 'message' => $observation->statusMessage()],
                static fn(mixed $value): bool => $value !== null,
            );
        }

        return $span;
    }

    /**
     * @param array<string, string|int|float|bool|list<string>> $attributes
     *
     * @return list<OtlpAttribute>
     */
    private static function attributes(array $attributes): array
    {
        $encoded = [];
        foreach ($attributes as $key => $value) {
            $encoded[] = ['key' => $key, 'value' => self::value($value)];
        }

        return $encoded;
    }

    /**
     * @param string|int|float|bool|list<string> $value
     *
     * @return array<string, mixed>
     */
    private static function value(string|int|float|bool|array $value): array
    {
        return match (true) {
            is_string($value) => ['stringValue' => $value],
            is_bool($value) => ['boolValue' => $value],
            is_int($value) => ['intValue' => (string) $value],
            is_float($value) => ['doubleValue' => $value],
            default => ['arrayValue' => ['values' => array_map(static fn(string $item): array => ['stringValue' => $item], $value)]],
        };
    }

    /**
     * Unix time in nanoseconds as a decimal string (microsecond precision).
     */
    private static function nanoseconds(DateTimeImmutable $time): string
    {
        return $time->format('Uu') . '000';
    }
}
