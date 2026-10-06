<?php

declare(strict_types=1);

/**
 * Shared setup for the smoke tests: loads .env.smoke, builds the client and a logger
 * that counts errors.
 */

use Mentax\LangfuseClient\Langfuse;
use Mentax\LangfuseClient\LangfuseConfig;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

require __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env.smoke';
if (is_file($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines === false ? [] : $lines as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

final class SmokeLogger extends AbstractLogger
{
    public int $errors = 0;

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ($level === 'error') {
            ++$this->errors;
        }
        $exception = $context['exception'] ?? null;
        $levelName = is_string($level) ? $level : get_debug_type($level);
        fwrite(STDERR, sprintf("[%s] %s%s\n", $levelName, $message, $exception instanceof Throwable ? ' ' . $exception->getMessage() : ''));
    }
}

final readonly class Smoke
{
    public function __construct(
        public LangfuseConfig $config,
        public HttpClientInterface $http,
        public Langfuse $langfuse,
        public SmokeLogger $logger,
    ) {}

    public static function create(): self
    {
        $config = LangfuseConfig::fromEnvironment();
        $http = HttpClient::create(['timeout' => 10]);
        $psr18 = new Psr18Client($http);

        return new self($config, $http, new Langfuse($config, $psr18, $psr18, $psr18), new SmokeLogger());
    }

    /**
     * Raw authenticated GET for read-back checks.
     *
     * @param array<string, string> $query
     *
     * @return array<mixed>
     */
    public function get(string $path, array $query): array
    {
        $response = $this->http->request('GET', $this->config->url($path), [
            'auth_basic' => [$this->config->publicKey, $this->config->secretKey],
            'query' => $query,
        ]);
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException(sprintf('GET %s failed with HTTP %d: %s', $path, $response->getStatusCode(), $response->getContent(false)));
        }

        return $response->toArray();
    }

    /**
     * @param array<string, bool> $checks
     */
    public static function report(array $checks, mixed $diagnostics): never
    {
        $failed = 0;
        foreach ($checks as $label => $ok) {
            printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $label);
            $failed += $ok ? 0 : 1;
        }
        if ($failed > 0) {
            fwrite(STDERR, "\nDiagnostics:\n" . json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            exit(1);
        }
        echo "\nAll checks passed.\n";
        exit(0);
    }
}
