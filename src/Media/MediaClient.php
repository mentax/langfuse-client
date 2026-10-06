<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Media;

use Mentax\LangfuseClient\Exception\InvalidResponseException;
use Mentax\LangfuseClient\Exception\LangfuseException;
use Mentax\LangfuseClient\Internal\ArrayReader;
use Mentax\LangfuseClient\Internal\HttpClient;
use Mentax\LangfuseClient\Internal\SystemClock;
use Psr\Clock\ClockInterface;

/**
 * Langfuse Media: upload files to Langfuse's object storage and read them back.
 *
 * Langfuse deduplicates by content hash per project, so uploading the same bytes
 * twice transfers them once.
 */
final readonly class MediaClient
{
    private ClockInterface $clock;

    public function __construct(
        private HttpClient $http,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * Uploads bytes and returns the reference to put into the target's JSON field.
     *
     * @param string $contentType MIME type from Langfuse's supported list, e.g. image/jpeg, application/pdf
     *
     * @throws LangfuseException when Langfuse rejects the file or the storage upload fails
     */
    public function upload(string $content, string $contentType, MediaTarget $target): MediaReference
    {
        $hash = base64_encode(hash('sha256', $content, true));

        $response = new ArrayReader($this->http->post('/api/public/media', [
            'contentType' => $contentType,
            'contentLength' => strlen($content),
            'sha256Hash' => $hash,
            ...$target->toArray(),
        ]), 'Langfuse media upload');

        $reference = new MediaReference($response->string('mediaId'), $contentType);
        $uploadUrl = $response->nullableString('uploadUrl');
        if ($uploadUrl === null) {
            return $reference; // Already stored with the same content.
        }

        $started = hrtime(true);
        $status = $this->http->putToPresignedUrl($uploadUrl, $content, [
            'Content-Type' => $contentType,
            'Content-Length' => (string) strlen($content),
            'x-amz-checksum-sha256' => $hash,
        ]);
        $uploadTimeMs = (int) round((hrtime(true) - $started) / 1_000_000);
        $succeeded = $status >= 200 && $status < 300;

        // Langfuse treats media as usable only after this report.
        $this->http->patch('/api/public/media/' . rawurlencode($reference->mediaId), [
            'uploadedAt' => $this->clock->now()->format('Y-m-d\TH:i:s.vP'),
            'uploadHttpStatus' => $status,
            'uploadHttpError' => $succeeded ? null : sprintf('Storage answered HTTP %d', $status),
            'uploadTimeMs' => $uploadTimeMs,
        ]);

        if (!$succeeded) {
            throw new LangfuseException(sprintf('Upload of media %s to storage failed with HTTP %d.', $reference->mediaId, $status));
        }

        return $reference;
    }

    /**
     * Uploads a local file, detecting the MIME type with ext-fileinfo unless given.
     */
    public function uploadFile(string $path, MediaTarget $target, ?string $contentType = null): MediaReference
    {
        $content = is_file($path) ? file_get_contents($path) : false;
        if ($content === false) {
            throw new LangfuseException(sprintf('File "%s" does not exist or is not readable.', $path));
        }

        if ($contentType === null && extension_loaded('fileinfo')) {
            $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
            $contentType = is_string($detected) ? $detected : null;
        }

        return $this->upload($content, $contentType ?? 'application/octet-stream', $target);
    }

    public function get(string $mediaId): Media
    {
        return Media::fromArray($this->http->get('/api/public/media/' . rawurlencode($mediaId)));
    }

    /**
     * Returns the file's bytes.
     */
    public function download(MediaReference|string $media): string
    {
        $mediaId = $media instanceof MediaReference ? $media->mediaId : $media;
        $content = $this->http->download($this->get($mediaId)->url);

        if ($content === '') {
            throw new InvalidResponseException(sprintf('Media %s downloaded empty.', $mediaId));
        }

        return $content;
    }
}
