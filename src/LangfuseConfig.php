<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Connection settings for one Langfuse project.
 *
 * Timeouts are not configured here: PSR-18 has no per-request timeout, so set
 * them on the HTTP client you pass in (see README, "HTTP client and timeouts").
 */
final readonly class LangfuseConfig
{
    public string $host;

    public function __construct(
        string $host,
        public string $publicKey,
        #[SensitiveParameter]
        public string $secretKey,
    ) {
        $host = rtrim($host, '/');
        if (filter_var($host, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException(sprintf('Invalid Langfuse host URL "%s".', $host));
        }
        if ($publicKey === '' || $secretKey === '') {
            throw new InvalidArgumentException('Langfuse public and secret keys must not be empty.');
        }
        $this->host = $host;
    }

    /**
     * Reads LANGFUSE_HOST (or LANGFUSE_BASE_URL), LANGFUSE_PUBLIC_KEY and LANGFUSE_SECRET_KEY,
     * the variable names used by the official Langfuse SDKs.
     */
    public static function fromEnvironment(): self
    {
        return new self(
            self::env('LANGFUSE_HOST') ?? self::env('LANGFUSE_BASE_URL') ?? 'https://cloud.langfuse.com',
            self::env('LANGFUSE_PUBLIC_KEY') ?? '',
            self::env('LANGFUSE_SECRET_KEY') ?? '',
        );
    }

    public function url(string $path): string
    {
        return $this->host . '/' . ltrim($path, '/');
    }

    public function authorizationHeader(): string
    {
        return 'Basic ' . base64_encode($this->publicKey . ':' . $this->secretKey);
    }

    public function __debugInfo(): array
    {
        return ['host' => $this->host, 'publicKey' => $this->publicKey, 'secretKey' => '***'];
    }

    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
