<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Prompt;

use Stringable;

/**
 * Langfuse's {{variable}} syntax.
 *
 * Variable names follow Langfuse's rule: a Unicode letter, then letters, digits or
 * underscores. Whitespace inside the braces is allowed, as in the Langfuse server
 * compiler. Anything else in double braces is left untouched.
 *
 * Substitution is a single pass, so a value that itself contains {{name}} is
 * inserted literally and never expanded.
 *
 * @internal
 */
final class Template
{
    private const VARIABLE_PATTERN = '/\{\{\s*(\p{L}[\p{L}\p{N}_]*)\s*\}\}/u';

    /**
     * @return list<string> unique variable names, in order of first appearance
     */
    public static function variables(string $template): array
    {
        preg_match_all(self::VARIABLE_PATTERN, $template, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @param array<string, string|Stringable|int|float> $values must contain every variable of the template
     */
    public static function render(string $template, array $values): string
    {
        $rendered = preg_replace_callback(
            self::VARIABLE_PATTERN,
            static fn(array $match): string => array_key_exists($match[1], $values) ? (string) $values[$match[1]] : $match[0],
            $template,
        );

        // preg_replace_callback returns null only on a PCRE error such as invalid UTF-8.
        return $rendered ?? $template;
    }
}
