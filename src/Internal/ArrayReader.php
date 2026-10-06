<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Internal;

use Mentax\LangfuseClient\Exception\InvalidResponseException;

/**
 * Typed access to decoded JSON, failing with InvalidResponseException instead of a TypeError.
 *
 * @internal
 */
final readonly class ArrayReader
{
    /**
     * @param array<mixed> $data
     */
    public function __construct(
        private array $data,
        private string $context,
    ) {}

    public function string(string $key): string
    {
        $value = $this->data[$key] ?? null;
        if (!is_string($value)) {
            throw $this->invalid($key, 'a string');
        }

        return $value;
    }

    public function nullableString(string $key): ?string
    {
        $value = $this->data[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw $this->invalid($key, 'a string or null');
        }

        return $value;
    }

    public function int(string $key): int
    {
        $value = $this->data[$key] ?? null;
        if (!is_int($value)) {
            throw $this->invalid($key, 'an integer');
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $value = $this->data[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw $this->invalid($key, 'a list of strings');
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw $this->invalid($key, 'a list of strings');
            }
        }

        /** @var list<string> $value */
        return $value;
    }

    /**
     * Missing and null both read as an empty map.
     *
     * @return array<string, mixed>
     */
    public function map(string $key): array
    {
        $value = $this->data[$key] ?? [];
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw $this->invalid($key, 'an object');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @return list<array<mixed>>
     */
    public function listOfArrays(string $key): array
    {
        $value = $this->data[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw $this->invalid($key, 'a list');
        }
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw $this->invalid($key, 'a list of objects');
            }
        }

        /** @var list<array<mixed>> $value */
        return $value;
    }

    private function invalid(string $key, string $expected): InvalidResponseException
    {
        return new InvalidResponseException(sprintf('%s: field "%s" must be %s.', $this->context, $key, $expected));
    }
}
