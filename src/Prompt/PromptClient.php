<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Prompt;

use Generator;
use InvalidArgumentException;
use Mentax\LangfuseClient\Exception\LangfuseException;
use Mentax\LangfuseClient\Exception\NotFoundException;
use Mentax\LangfuseClient\Internal\ArrayReader;
use Mentax\LangfuseClient\Internal\HttpClient;

/**
 * Prompt management endpoints (/api/public/v2/prompts). No caching: wrap it in
 * CachedPromptProvider for runtime use.
 */
final readonly class PromptClient implements PromptProviderInterface
{
    public const DEFAULT_LABEL = 'production';

    private const PAGE_SIZE = 100;

    public function __construct(
        private HttpClient $http,
    ) {}

    /**
     * Without a label or version, Langfuse returns the version labelled "production".
     *
     * @throws NotFoundException  no such prompt, or no version with that label/version
     * @throws LangfuseException  on any other failure
     */
    public function get(string $name, ?string $label = null, ?int $version = null): TextPrompt|ChatPrompt
    {
        if ($label !== null && $version !== null) {
            throw new InvalidArgumentException('Pass either a label or a version, not both.');
        }

        return Prompt::fromArray($this->http->get(
            self::promptPath($name),
            ['label' => $label, 'version' => $version],
        ));
    }

    /**
     * All prompts of the project, fetched page by page. Metadata only: Langfuse has no
     * bulk endpoint for prompt content, so each version must be fetched with get().
     *
     * @param string|null $label only prompts that have this label on some version
     * @param string|null $tag only prompts with this tag
     *
     * @return Generator<int, PromptMetadata>
     *
     * @throws LangfuseException
     */
    public function list(?string $label = null, ?string $tag = null): Generator
    {
        $page = 1;
        do {
            $response = new ArrayReader(
                $this->http->get('/api/public/v2/prompts', ['label' => $label, 'tag' => $tag, 'page' => $page, 'limit' => self::PAGE_SIZE]),
                'Langfuse prompt list',
            );
            foreach ($response->listOfArrays('data') as $prompt) {
                yield PromptMetadata::fromArray($prompt);
            }
            $totalPages = (new ArrayReader($response->map('meta'), 'Langfuse prompt list meta'))->int('totalPages');
            ++$page;
        } while ($page <= $totalPages);
    }

    /**
     * Creates a new version of a text prompt (or the prompt itself, if it does not exist yet).
     *
     * @param list<string> $labels
     * @param array<string, mixed> $config
     * @param list<string> $tags
     */
    public function createText(
        string $name,
        string $prompt,
        array $labels = [],
        array $config = [],
        array $tags = [],
        ?string $commitMessage = null,
    ): TextPrompt {
        $created = $this->create($name, PromptType::Text, $prompt, $labels, $config, $tags, $commitMessage);
        if (!$created instanceof TextPrompt) {
            throw new LangfuseException(sprintf('Langfuse returned a %s prompt for text prompt "%s".', $created->type()->value, $name));
        }

        return $created;
    }

    /**
     * Creates a new version of a chat prompt (or the prompt itself, if it does not exist yet).
     *
     * @param list<ChatMessage|MessagePlaceholder> $messages
     * @param list<string> $labels
     * @param array<string, mixed> $config
     * @param list<string> $tags
     */
    public function createChat(
        string $name,
        array $messages,
        array $labels = [],
        array $config = [],
        array $tags = [],
        ?string $commitMessage = null,
    ): ChatPrompt {
        $payload = array_map(static fn(ChatMessage|MessagePlaceholder $message): array => $message->toArray(), $messages);
        $created = $this->create($name, PromptType::Chat, $payload, $labels, $config, $tags, $commitMessage);
        if (!$created instanceof ChatPrompt) {
            throw new LangfuseException(sprintf('Langfuse returned a %s prompt for chat prompt "%s".', $created->type()->value, $name));
        }

        return $created;
    }

    /**
     * Replaces the labels of one version. Langfuse moves a label that is already on
     * another version (e.g. "production") to this one.
     *
     * @param list<string> $labels
     */
    public function setLabels(string $name, int $version, array $labels): TextPrompt|ChatPrompt
    {
        return Prompt::fromArray($this->http->patch(
            sprintf('%s/versions/%d', self::promptPath($name), $version),
            ['newLabels' => $labels],
        ));
    }

    /**
     * @param string|list<array<string, string>> $prompt
     * @param list<string> $labels
     * @param array<string, mixed> $config
     * @param list<string> $tags
     */
    private function create(
        string $name,
        PromptType $type,
        string|array $prompt,
        array $labels,
        array $config,
        array $tags,
        ?string $commitMessage,
    ): TextPrompt|ChatPrompt {
        $body = [
            'name' => $name,
            'type' => $type->value,
            'prompt' => $prompt,
            'labels' => $labels,
            'config' => (object) $config,
            'tags' => $tags,
        ];
        if ($commitMessage !== null) {
            $body['commitMessage'] = $commitMessage;
        }

        return Prompt::fromArray($this->http->post('/api/public/v2/prompts', $body));
    }

    /**
     * Prompt names may contain "/" (folders); it must be encoded to stay in one path segment.
     */
    private static function promptPath(string $name): string
    {
        return '/api/public/v2/prompts/' . rawurlencode($name);
    }
}
