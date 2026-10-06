<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Prompt;

use Mentax\LangfuseClient\Exception\PromptCompilationException;
use Stringable;

final readonly class ChatPrompt extends Prompt
{
    /**
     * @param list<ChatMessage|MessagePlaceholder> $messages
     * @param list<string> $labels
     * @param list<string> $tags
     * @param array<string, mixed> $config
     */
    public function __construct(
        string $name,
        int $version,
        public array $messages,
        array $labels = [],
        array $tags = [],
        array $config = [],
        ?string $commitMessage = null,
    ) {
        parent::__construct($name, $version, $labels, $tags, $config, $commitMessage);
    }

    public function type(): PromptType
    {
        return PromptType::Chat;
    }

    public function variables(): array
    {
        $variables = [];
        foreach ($this->messages as $message) {
            if ($message instanceof ChatMessage) {
                $variables = [...$variables, ...Template::variables($message->content)];
            }
        }

        return array_values(array_unique($variables));
    }

    /**
     * @return list<string>
     */
    public function placeholders(): array
    {
        $names = [];
        foreach ($this->messages as $message) {
            if ($message instanceof MessagePlaceholder) {
                $names[] = $message->name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Fills {{variables}} in every message and replaces each placeholder with the
     * messages given for it. Placeholder messages are inserted as they are, without
     * variable substitution.
     *
     * @param array<string, string|Stringable|int|float> $variables exactly the variables the prompt uses
     * @param array<string, list<ChatMessage|array<string, mixed>>> $placeholders exactly the placeholders the prompt declares
     *
     * @return list<array<string, mixed>>
     *
     * @throws PromptCompilationException
     */
    public function compile(array $variables = [], array $placeholders = []): array
    {
        $this->assertNames(
            [...$this->variables(), ...$this->placeholders()],
            [...array_map(strval(...), array_keys($variables)), ...array_map(strval(...), array_keys($placeholders))],
        );

        $compiled = [];
        foreach ($this->messages as $message) {
            if ($message instanceof ChatMessage) {
                $compiled[] = ['role' => $message->role, 'content' => Template::render($message->content, $variables)];
                continue;
            }
            foreach ($placeholders[$message->name] as $inserted) {
                $compiled[] = $inserted instanceof ChatMessage ? $inserted->toArray() : $inserted;
            }
        }

        return $compiled;
    }

    public function toArray(): array
    {
        return [
            'prompt' => array_map(static fn(ChatMessage|MessagePlaceholder $message): array => $message->toArray(), $this->messages),
        ] + $this->metadataToArray();
    }
}
