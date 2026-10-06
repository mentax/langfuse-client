<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Navigates decoded JSON in tests, failing the test when a path or type does not match.
 */
final readonly class DecodedJson
{
    public function __construct(
        private mixed $value,
        private string $path = '$',
    ) {}

    public function at(string|int ...$keys): self
    {
        $current = $this;
        foreach ($keys as $key) {
            $array = $current->array();
            Assert::assertArrayHasKey($key, $array, sprintf('Missing %s.%s', $current->path, $key));
            $current = new self($array[$key], $current->path . '.' . $key);
        }

        return $current;
    }

    /**
     * @return array<mixed>
     */
    public function array(): array
    {
        Assert::assertIsArray($this->value, $this->path . ' is not an array');

        return $this->value;
    }

    public function string(): string
    {
        Assert::assertIsString($this->value, $this->path . ' is not a string');

        return $this->value;
    }

    public function has(string|int $key): bool
    {
        return array_key_exists($key, $this->array());
    }
}
