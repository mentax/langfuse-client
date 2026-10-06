<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Prompt;

use Mentax\LangfuseClient\Exception\InvalidResponseException;
use Mentax\LangfuseClient\Exception\PromptCompilationException;
use Mentax\LangfuseClient\Internal\ArrayReader;

/**
 * One version of a Langfuse prompt.
 *
 * compile() is strict: passing fewer or more variables than the prompt uses throws
 * PromptCompilationException. A prompt edited in Langfuse that no longer matches the
 * code calling it fails loudly instead of sending a half-filled prompt to a model.
 */
abstract readonly class Prompt
{
    /**
     * @param list<string> $labels
     * @param list<string> $tags
     * @param array<string, mixed> $config free-form JSON stored with the version, e.g. model and temperature
     */
    public function __construct(
        public string $name,
        public int $version,
        public array $labels = [],
        public array $tags = [],
        public array $config = [],
        public ?string $commitMessage = null,
    ) {}

    abstract public function type(): PromptType;

    /**
     * @return list<string> names of the {{variables}} used anywhere in the prompt
     */
    abstract public function variables(): array;

    /**
     * The prompt in the shape of the Langfuse API response; fromArray() reads it back.
     *
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;

    /**
     * @param array<mixed> $data a prompt object as returned by GET /api/public/v2/prompts/{name}
     */
    public static function fromArray(array $data): TextPrompt|ChatPrompt
    {
        $reader = new ArrayReader($data, 'Langfuse prompt');
        $name = $reader->string('name');
        $version = $reader->int('version');
        $labels = $reader->stringList('labels');
        $tags = $reader->stringList('tags');
        $config = $reader->map('config');
        $commitMessage = $reader->nullableString('commitMessage');

        return match ($reader->string('type')) {
            PromptType::Text->value => new TextPrompt($name, $version, $reader->string('prompt'), $labels, $tags, $config, $commitMessage),
            PromptType::Chat->value => new ChatPrompt($name, $version, self::readMessages($reader, $name), $labels, $tags, $config, $commitMessage),
            default => throw new InvalidResponseException(sprintf('Langfuse prompt "%s" has an unsupported type "%s".', $name, $reader->string('type'))),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function metadataToArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'type' => $this->type()->value,
            'labels' => $this->labels,
            'tags' => $this->tags,
            'config' => $this->config,
            'commitMessage' => $this->commitMessage,
        ];
    }

    /**
     * @param list<string> $expected
     * @param list<string> $given
     */
    protected function assertNames(array $expected, array $given): void
    {
        $missing = array_values(array_diff($expected, $given));
        $unexpected = array_values(array_diff($given, $expected));

        if ($missing !== [] || $unexpected !== []) {
            throw new PromptCompilationException($this->name, $missing, $unexpected);
        }
    }

    /**
     * @return list<ChatMessage|MessagePlaceholder>
     */
    private static function readMessages(ArrayReader $reader, string $name): array
    {
        $messages = [];
        foreach ($reader->listOfArrays('prompt') as $index => $message) {
            $messageReader = new ArrayReader($message, sprintf('Langfuse prompt "%s", message %d', $name, $index));
            $messages[] = ($message['type'] ?? null) === MessagePlaceholder::TYPE
                ? new MessagePlaceholder($messageReader->string('name'))
                : new ChatMessage($messageReader->string('role'), $messageReader->string('content'));
        }

        return $messages;
    }
}
