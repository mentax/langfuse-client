<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Prompt;

enum PromptType: string
{
    case Text = 'text';
    case Chat = 'chat';
}
