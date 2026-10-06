<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Prompt;

use Mentax\LangfuseClient\Exception\PromptCompilationException;
use Stringable;

final readonly class TextPrompt extends Prompt
{
    /**
     * @param list<string> $labels
     * @param list<string> $tags
     * @param array<string, mixed> $config
     */
    public function __construct(
        string $name,
        int $version,
        public string $prompt,
        array $labels = [],
        array $tags = [],
        array $config = [],
        ?string $commitMessage = null,
    ) {
        parent::__construct($name, $version, $labels, $tags, $config, $commitMessage);
    }

    public function type(): PromptType
    {
        return PromptType::Text;
    }

    public function variables(): array
    {
        return Template::variables($this->prompt);
    }

    /**
     * @param array<string, string|Stringable|int|float> $variables exactly the variables the prompt uses
     *
     * @throws PromptCompilationException
     */
    public function compile(array $variables = []): string
    {
        $this->assertNames($this->variables(), array_map(strval(...), array_keys($variables)));

        return Template::render($this->prompt, $variables);
    }

    public function toArray(): array
    {
        return ['prompt' => $this->prompt] + $this->metadataToArray();
    }
}
