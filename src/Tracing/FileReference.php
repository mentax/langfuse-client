<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Tracing;

use InvalidArgumentException;

/**
 * A pointer to a file that was sent to a model, recorded on an observation instead
 * of the file itself. The bytes stay in your storage; Langfuse keeps the identifier,
 * a link to view the file, and a hash to tell whether it changed.
 */
final readonly class FileReference
{
    /**
     * @param string      $id       your identifier of the file (document ID, storage key)
     * @param string|null $url      where an authorised person can open the file, e.g. your
     *                              application's document viewer; never a public link to personal data
     * @param string|null $sha256   lowercase hex SHA-256 of the content
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $mimeType = null,
        public ?string $url = null,
        public ?string $sha256 = null,
        public ?int $size = null,
    ) {
        if ($sha256 !== null && preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
            throw new InvalidArgumentException('sha256 must be 64 lowercase hex characters.');
        }
    }

    /**
     * Builds a reference from a local file, computing its size, hash and (with ext-fileinfo) MIME type.
     */
    public static function fromLocalFile(string $path, string $id, ?string $url = null, ?string $name = null): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException(sprintf('File "%s" does not exist or is not readable.', $path));
        }

        $size = filesize($path);
        $hash = hash_file('sha256', $path);
        $mimeType = null;
        if (extension_loaded('fileinfo')) {
            $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            $mimeType = is_string($detected) ? $detected : null;
        }

        return new self(
            id: $id,
            name: $name ?? basename($path),
            mimeType: $mimeType,
            url: $url,
            sha256: $hash === false ? null : $hash,
            size: $size === false ? null : $size,
        );
    }

    /**
     * Markdown for an input or output text: an inline image for images with a URL,
     * a link for other files, plain text without a URL.
     */
    public function toMarkdown(): string
    {
        $label = str_replace(['[', ']'], ['(', ')'], $this->name);

        if ($this->url === null) {
            return sprintf('%s (%s)', $label, $this->id);
        }

        return $this->mimeType !== null && str_starts_with($this->mimeType, 'image/')
            ? sprintf('![%s](%s)', $label, $this->url)
            : sprintf('[%s](%s)', $label, $this->url);
    }

    /**
     * @return array<string, string|int>
     */
    public function toArray(): array
    {
        return array_filter(
            [
                'id' => $this->id,
                'name' => $this->name,
                'mimeType' => $this->mimeType,
                'url' => $this->url,
                'sha256' => $this->sha256,
                'size' => $this->size,
            ],
            static fn(string|int|null $value): bool => $value !== null,
        );
    }
}
