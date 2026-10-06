<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Tracing;

use JsonSerializable;
use Stringable;

/**
 * Encoding of observation payloads. Tracing must not break the traced code, so
 * values that cannot be encoded degrade instead of throwing.
 *
 * @internal
 */
final class Json
{
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

    /**
     * Strings, and objects that serialise to a string, are sent as they are; everything
     * else as JSON.
     */
    public static function encodeValue(mixed $value): string
    {
        if ($value instanceof JsonSerializable) {
            $value = $value->jsonSerialize();
        }
        if (is_string($value)) {
            return $value;
        }
        if ($value instanceof Stringable && !$value instanceof JsonSerializable) {
            return (string) $value;
        }

        return self::encode($value);
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function encodeObject(array $value): string
    {
        return self::encode((object) $value);
    }

    /**
     * Replaces invalid UTF-8 sequences with U+FFFD, so that one bad byte does not make
     * the whole export request unencodable.
     */
    public static function validUtf8(string $value): string
    {
        if (preg_match('//u', $value) === 1) {
            return $value;
        }

        $decoded = json_decode((string) json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE));

        return is_string($decoded) ? $decoded : '[invalid UTF-8]';
    }

    private static function encode(mixed $value): string
    {
        $json = json_encode($value, self::FLAGS);

        return $json === false ? '"[unencodable value]"' : $json;
    }
}
