<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Dataset;

enum DatasetItemStatus: string
{
    case Active = 'ACTIVE';
    case Archived = 'ARCHIVED';
}
