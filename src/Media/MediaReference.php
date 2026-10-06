<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Media;

use JsonSerializable;
use Stringable;

/**
 * A file stored in Langfuse Media, written into JSON as
 * "@@@langfuseMedia:type=image/jpeg|id=...|source=bytes@@@".
 *
 * Langfuse recognises a reference only when it is the entire value of a JSON field,
 * e.g. {"photo": "@@@langfuseMedia:...@@@"}. Inside a longer text it is ignored.
 * The object can be placed in input/output arrays directly; it serialises to that string.
 */
final readonly class MediaReference implements JsonSerializable, Stringable
{
    private const PATTERN = '/^@@@langfuseMedia:(.*)@@@$/';

    public function __construct(
        public string $mediaId,
        public string $contentType,
        public string $source = 'bytes',
    ) {}

    public static function tryParse(string $value): ?self
    {
        if (preg_match(self::PATTERN, $value, $match) !== 1) {
            return null;
        }

        $fields = [];
        foreach (explode('|', $match[1]) as $part) {
            [$key, $fieldValue] = array_pad(explode('=', $part, 2), 2, '');
            $fields[$key] = $fieldValue;
        }

        if (($fields['id'] ?? '') === '' || ($fields['type'] ?? '') === '') {
            return null;
        }

        return new self($fields['id'], $fields['type'], $fields['source'] ?? 'bytes');
    }

    public function __toString(): string
    {
        return sprintf('@@@langfuseMedia:type=%s|id=%s|source=%s@@@', $this->contentType, $this->mediaId, $this->source);
    }

    public function jsonSerialize(): string
    {
        return (string) $this;
    }
}
