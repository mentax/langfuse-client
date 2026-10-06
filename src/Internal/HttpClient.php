<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Internal;

use JsonException;
use Mentax\LangfuseClient\Exception\ApiException;
use Mentax\LangfuseClient\Exception\InvalidResponseException;
use Mentax\LangfuseClient\Exception\TransportException;
use Mentax\LangfuseClient\LangfuseConfig;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Thin JSON-over-HTTP layer for the Langfuse public API.
 *
 * @internal
 */
final readonly class HttpClient
{
    public const USER_AGENT = 'mentax-langfuse-client';

    public function __construct(
        private LangfuseConfig $config,
        private ClientInterface $client,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    /**
     * @param array<string, scalar|null> $query
     *
     * @return array<mixed>
     */
    public function get(string $path, array $query = []): array
    {
        $query = array_filter($query, static fn(mixed $value): bool => $value !== null);
        if ($query !== []) {
            $path .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $this->send('GET', $path);
    }

    /**
     * @param array<mixed> $body
     * @param array<string, string> $headers
     *
     * @return array<mixed>
     */
    public function post(string $path, array $body, array $headers = []): array
    {
        return $this->send('POST', $path, $body, $headers);
    }

    /**
     * @param array<mixed> $body
     *
     * @return array<mixed>
     */
    public function patch(string $path, array $body): array
    {
        return $this->send('PATCH', $path, $body);
    }

    /**
     * @param array<mixed>|null $body
     * @param array<string, string> $headers
     *
     * @return array<mixed>
     */
    private function send(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        $uri = $this->config->url($path);
        $request = $this->requestFactory->createRequest($method, $uri)
            ->withHeader('Authorization', $this->config->authorizationHeader())
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', self::USER_AGENT);

        if ($body !== null) {
            try {
                $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            } catch (JsonException $e) {
                throw new InvalidResponseException(sprintf('Cannot encode request body for %s %s: %s', $method, $path, $e->getMessage()), 0, $e);
            }
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($json));
        }

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException(sprintf('Langfuse %s %s failed: %s', $method, $path, $e->getMessage()), 0, $e);
        }

        $status = $response->getStatusCode();
        $contents = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            throw ApiException::fromResponse($method, $path, $status, $contents);
        }

        if (trim($contents) === '') {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidResponseException(sprintf('Langfuse %s %s returned invalid JSON: %s', $method, $path, $e->getMessage()), 0, $e);
        }

        if (!is_array($decoded)) {
            throw new InvalidResponseException(sprintf('Langfuse %s %s returned JSON that is not an object.', $method, $path));
        }

        return $decoded;
    }
}
