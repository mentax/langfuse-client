<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Tests\Support;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * PSR-18 client that records requests and replays queued responses or exceptions.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $queue = [];

    /**
     * @param array<mixed> $body
     */
    public function respondJson(array $body, int $status = 200): self
    {
        $this->queue[] = new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));

        return $this;
    }

    public function respond(int $status, string $body = ''): self
    {
        $this->queue[] = new Response($status, [], $body);

        return $this;
    }

    public function failWithNetworkError(): self
    {
        $this->queue[] = new class ('Connection refused') extends RuntimeException implements ClientExceptionInterface {};

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue) ?? throw new RuntimeException('No response queued for ' . $request->getUri());
        if ($next instanceof ClientExceptionInterface) {
            throw $next;
        }

        return $next;
    }

    public function lastRequest(): RequestInterface
    {
        return $this->requests[array_key_last($this->requests)] ?? throw new RuntimeException('No request was sent.');
    }

    /**
     * @return array<mixed>
     */
    public function lastRequestJson(): array
    {
        $decoded = json_decode((string) $this->lastRequest()->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Request body is not a JSON object.');
        }

        return $decoded;
    }
}
