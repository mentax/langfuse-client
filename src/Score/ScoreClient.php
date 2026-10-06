<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Score;

use InvalidArgumentException;
use Mentax\LangfuseClient\Internal\ArrayReader;
use Mentax\LangfuseClient\Internal\HttpClient;

/**
 * Sends scores (evaluation results, user feedback) to Langfuse, one request per score.
 *
 * A score belongs to exactly one of: a trace (optionally one observation in it),
 * a session, or an experiment run.
 */
final readonly class ScoreClient
{
    public function __construct(
        private HttpClient $http,
    ) {}

    /**
     * @param float|int|bool|string $value bool for BOOLEAN, string for CATEGORICAL and TEXT, a number otherwise
     * @param string|null $id idempotency key: sending the same ID again updates the score
     * @param array<string, mixed> $metadata
     *
     * @return string the score ID
     */
    public function create(
        string $name,
        float|int|bool|string $value,
        ?string $traceId = null,
        ?string $observationId = null,
        ?string $sessionId = null,
        ?string $experimentRunId = null,
        ?ScoreDataType $dataType = null,
        ?string $comment = null,
        array $metadata = [],
        ?string $environment = null,
        ?string $id = null,
    ): string {
        $targets = count(array_filter([$traceId, $sessionId, $experimentRunId], static fn(?string $target): bool => $target !== null));
        if ($targets !== 1) {
            throw new InvalidArgumentException('A score needs exactly one of traceId, sessionId or experimentRunId.');
        }
        if ($observationId !== null && $traceId === null) {
            throw new InvalidArgumentException('observationId requires traceId.');
        }

        $dataType ??= match (true) {
            is_bool($value) => ScoreDataType::Boolean,
            is_string($value) => ScoreDataType::Categorical,
            default => ScoreDataType::Numeric,
        };
        if (is_bool($value)) {
            $value = $value ? 1 : 0;
        }
        if (is_string($value) !== in_array($dataType, [ScoreDataType::Categorical, ScoreDataType::Text], true)) {
            throw new InvalidArgumentException(sprintf('Value type does not match score data type %s.', $dataType->value));
        }

        $body = array_filter(
            [
                'id' => $id,
                'name' => $name,
                'value' => $value,
                'dataType' => $dataType->value,
                'traceId' => $traceId,
                'observationId' => $observationId,
                'sessionId' => $sessionId,
                'datasetRunId' => $experimentRunId,
                'comment' => $comment,
                'metadata' => $metadata === [] ? null : $metadata,
                'environment' => $environment,
            ],
            static fn(mixed $field): bool => $field !== null,
        );

        return (new ArrayReader($this->http->post('/api/public/scores', $body), 'Langfuse score'))->string('id');
    }
}
