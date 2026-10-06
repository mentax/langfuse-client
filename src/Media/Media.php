<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Media;

use DateTimeImmutable;
use Exception;
use Mentax\LangfuseClient\Exception\InvalidResponseException;
use Mentax\LangfuseClient\Internal\ArrayReader;

/**
 * Metadata of a stored file, with a presigned download URL valid until $urlExpiry.
 */
final readonly class Media
{
    public function __construct(
        public string $mediaId,
        public string $contentType,
        public int $contentLength,
        public string $url,
        public ?DateTimeImmutable $urlExpiry,
    ) {}

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $reader = new ArrayReader($data, 'Langfuse media');
        $expiry = $reader->nullableString('urlExpiry');
        try {
            $urlExpiry = $expiry === null ? null : new DateTimeImmutable($expiry);
        } catch (Exception $e) {
            throw new InvalidResponseException(sprintf('Langfuse media: invalid urlExpiry "%s".', $expiry), 0, $e);
        }

        return new self(
            $reader->string('mediaId'),
            $reader->string('contentType'),
            $reader->int('contentLength'),
            $reader->string('url'),
            $urlExpiry,
        );
    }

    public function reference(): MediaReference
    {
        return new MediaReference($this->mediaId, $this->contentType);
    }
}
