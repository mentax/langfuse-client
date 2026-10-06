<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Prompt;

/**
 * A slot in a chat prompt that the caller fills with a list of messages at compile time,
 * e.g. conversation history.
 */
final readonly class MessagePlaceholder
{
    public const TYPE = 'placeholder';

    public function __construct(
        public string $name,
    ) {}

    /**
     * @return array{type: 'placeholder', name: string}
     */
    public function toArray(): array
    {
        return ['type' => self::TYPE, 'name' => $this->name];
    }
}
