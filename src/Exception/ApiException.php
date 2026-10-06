<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Exception;

use Throwable;

/**
 * Langfuse answered with a non-2xx status.
 */
class ApiException extends LangfuseException
{
    private const BODY_EXCERPT_LENGTH = 500;

    final public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly string $responseBody = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public static function fromResponse(string $method, string $uri, int $statusCode, string $body): self
    {
        $excerpt = mb_substr($body, 0, self::BODY_EXCERPT_LENGTH);
        $message = sprintf('Langfuse %s %s failed with HTTP %d: %s', $method, $uri, $statusCode, $excerpt);

        return match ($statusCode) {
            404 => new NotFoundException($message, $statusCode, $excerpt),
            401, 403 => new AuthenticationException($message, $statusCode, $excerpt),
            default => new self($message, $statusCode, $excerpt),
        };
    }
}
