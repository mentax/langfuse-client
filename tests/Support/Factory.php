<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Tests\Support;

use Mentax\LangfuseClient\Internal\HttpClient;
use Mentax\LangfuseClient\LangfuseConfig;
use Nyholm\Psr7\Factory\Psr17Factory;

final class Factory
{
    public static function config(): LangfuseConfig
    {
        return new LangfuseConfig('http://langfuse.test:3000', 'pk-lf-test', 'sk-lf-test');
    }

    public static function http(FakeHttpClient $client): HttpClient
    {
        $psr17 = new Psr17Factory();

        return new HttpClient(self::config(), $client, $psr17, $psr17);
    }

    /**
     * A prompt object as returned by GET /api/public/v2/prompts/{name}.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function textPromptResponse(array $overrides = []): array
    {
        return array_replace([
            'id' => 'cm123',
            'name' => 'damageaudit/airbag-photo',
            'version' => 3,
            'type' => 'text',
            'prompt' => 'Check {{documents_count}} photos for airbags.',
            'config' => ['model' => 'gemini-2.5-flash-lite', 'temperature' => 0],
            'labels' => ['production', 'latest'],
            'tags' => ['damageaudit'],
            'commitMessage' => 'Initial import',
            'projectId' => 'project-1',
            'createdBy' => 'API',
            'createdAt' => '2026-10-06T10:00:00.000Z',
            'updatedAt' => '2026-10-06T10:00:00.000Z',
            'resolutionGraph' => null,
        ], $overrides);
    }
}
