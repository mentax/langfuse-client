<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Score;

enum ScoreDataType: string
{
    case Numeric = 'NUMERIC';
    case Boolean = 'BOOLEAN';
    case Categorical = 'CATEGORICAL';
    case Text = 'TEXT';
}
