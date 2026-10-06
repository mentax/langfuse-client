<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Prompt;

use Mentax\LangfuseClient\Exception\LangfuseException;

interface PromptProviderInterface
{
    /**
     * Returns one prompt version, selected by label or by version number.
     * Without either, the version labelled "production" is returned.
     *
     * @throws LangfuseException when the prompt cannot be provided
     */
    public function get(string $name, ?string $label = null, ?int $version = null): TextPrompt|ChatPrompt;
}
