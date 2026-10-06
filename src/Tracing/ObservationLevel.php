<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Tracing;

enum ObservationLevel: string
{
    case Debug = 'DEBUG';
    case Default = 'DEFAULT';
    case Warning = 'WARNING';
    case Error = 'ERROR';
}
