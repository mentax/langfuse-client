<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Tracing;

use InvalidArgumentException;

/**
 * Token counts of one model call, sent as langfuse.observation.usage_details.
 *
 * Langfuse prices usage per key, so keys must match the usage types of the model
 * definition in Langfuse: "input" and "output" for most models, plus optional keys
 * such as "input_cached_tokens" or "output_reasoning_tokens".
 */
final readonly class Usage
{
    /**
     * @param array<string, int> $details additional usage keys
     */
    public function __construct(
        public ?int $input = null,
        public ?int $output = null,
        public ?int $total = null,
        public array $details = [],
    ) {
        foreach (['input' => $input, 'output' => $output, 'total' => $total, ...$details] as $key => $value) {
            if ($value !== null && $value < 0) {
                throw new InvalidArgumentException(sprintf('Usage "%s" must not be negative, %d given.', $key, $value));
            }
        }
    }

    /**
     * Maps the usageMetadata object of a Gemini generateContent response
     * (Vertex AI or Google AI Studio).
     *
     * Thinking tokens are billed as output, so "output" is candidates + thoughts;
     * the thinking and cached shares are also reported separately.
     *
     * @param array<string, mixed> $usageMetadata
     */
    public static function fromGeminiUsageMetadata(array $usageMetadata): self
    {
        $count = static fn(string $key): int => is_int($usageMetadata[$key] ?? null) ? $usageMetadata[$key] : 0;

        $thoughts = $count('thoughtsTokenCount');
        $cached = $count('cachedContentTokenCount');
        $details = array_filter(
            ['output_reasoning_tokens' => $thoughts, 'input_cached_tokens' => $cached],
            static fn(int $value): bool => $value > 0,
        );

        return new self(
            input: $count('promptTokenCount'),
            output: $count('candidatesTokenCount') + $thoughts,
            total: isset($usageMetadata['totalTokenCount']) ? $count('totalTokenCount') : null,
            details: $details,
        );
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return array_filter(
            ['input' => $this->input, 'output' => $this->output, 'total' => $this->total],
            static fn(?int $value): bool => $value !== null,
        ) + $this->details;
    }
}
