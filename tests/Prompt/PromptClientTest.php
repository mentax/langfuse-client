<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Tests\Prompt;

use InvalidArgumentException;
use Mentax\LangfuseClient\Exception\ApiException;
use Mentax\LangfuseClient\Exception\AuthenticationException;
use Mentax\LangfuseClient\Exception\NotFoundException;
use Mentax\LangfuseClient\Exception\TransportException;
use Mentax\LangfuseClient\Prompt\ChatMessage;
use Mentax\LangfuseClient\Prompt\MessagePlaceholder;
use Mentax\LangfuseClient\Prompt\PromptClient;
use Mentax\LangfuseClient\Tests\Support\Factory;
use Mentax\LangfuseClient\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class PromptClientTest extends TestCase
{
    private FakeHttpClient $http;

    private PromptClient $client;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->client = new PromptClient(Factory::http($this->http));
    }

    public function testGetEncodesFolderNameAndSendsLabelAndBasicAuth(): void
    {
        $this->http->respondJson(Factory::textPromptResponse());

        $prompt = $this->client->get('damageaudit/airbag-photo', label: 'staging');

        $request = $this->http->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame('http://langfuse.test:3000/api/public/v2/prompts/damageaudit%2Fairbag-photo?label=staging', (string) $request->getUri());
        self::assertSame('Basic ' . base64_encode('pk-lf-test:sk-lf-test'), $request->getHeaderLine('Authorization'));
        self::assertSame(3, $prompt->version);
    }

    public function testGetByVersion(): void
    {
        $this->http->respondJson(Factory::textPromptResponse());

        $this->client->get('p', version: 7);

        self::assertSame('version=7', $this->http->lastRequest()->getUri()->getQuery());
    }

    public function testRejectsLabelAndVersionTogether(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->client->get('p', 'production', 1);
    }

    public function testNotFound(): void
    {
        $this->http->respond(404, '{"message":"Prompt not found"}');

        $this->expectException(NotFoundException::class);
        $this->client->get('missing');
    }

    public function testAuthenticationFailure(): void
    {
        $this->http->respond(401, '{"message":"Invalid credentials"}');

        $this->expectException(AuthenticationException::class);
        $this->client->get('p');
    }

    public function testServerErrorKeepsStatusAndBody(): void
    {
        $this->http->respond(503, 'maintenance');

        try {
            $this->client->get('p');
            self::fail('Expected ApiException.');
        } catch (ApiException $e) {
            self::assertSame(503, $e->statusCode);
            self::assertSame('maintenance', $e->responseBody);
        }
    }

    public function testNetworkFailureBecomesTransportException(): void
    {
        $this->http->failWithNetworkError();

        $this->expectException(TransportException::class);
        $this->client->get('p');
    }

    public function testCreateTextSendsConfigAsObject(): void
    {
        $this->http->respondJson(Factory::textPromptResponse(['version' => 4, 'labels' => ['staging']]));

        $prompt = $this->client->createText('p', 'Hello {{name}}', ['staging'], commitMessage: 'Shorter');

        self::assertSame('POST', $this->http->lastRequest()->getMethod());
        self::assertSame('/api/public/v2/prompts', $this->http->lastRequest()->getUri()->getPath());
        self::assertSame(
            '{"name":"p","type":"text","prompt":"Hello {{name}}","labels":["staging"],"config":{},"tags":[],"commitMessage":"Shorter"}',
            (string) $this->http->lastRequest()->getBody(),
        );
        self::assertSame(4, $prompt->version);
    }

    public function testCreateChatSerializesPlaceholders(): void
    {
        $this->http->respondJson(Factory::textPromptResponse([
            'type' => 'chat',
            'prompt' => [['role' => 'system', 'content' => 'x'], ['type' => 'placeholder', 'name' => 'history']],
        ]));

        $this->client->createChat('p', [new ChatMessage('system', 'x'), new MessagePlaceholder('history')]);

        self::assertSame(
            [['role' => 'system', 'content' => 'x'], ['type' => 'placeholder', 'name' => 'history']],
            $this->http->lastRequestJson()['prompt'],
        );
    }

    public function testSetLabelsPatchesTheVersion(): void
    {
        $this->http->respondJson(Factory::textPromptResponse(['labels' => ['production']]));

        $this->client->setLabels('damageaudit/vin', 5, ['production']);

        $request = $this->http->lastRequest();
        self::assertSame('PATCH', $request->getMethod());
        self::assertSame('/api/public/v2/prompts/damageaudit%2Fvin/versions/5', $request->getUri()->getPath());
        self::assertSame(['newLabels' => ['production']], $this->http->lastRequestJson());
    }
}
