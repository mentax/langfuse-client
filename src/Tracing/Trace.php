<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Tracing;

/**
 * The root span of a trace. Its input and output become the trace's input and output
 * in Langfuse; user, session, tags and visibility apply to the whole trace.
 */
final class Trace extends Observation
{
    public function setUserId(?string $userId): static
    {
        $this->context->userId = $userId;

        return $this;
    }

    /**
     * Groups traces into a Langfuse session. Never pass an authentication-relevant
     * identifier (PHP session ID, token): Langfuse users can read it.
     */
    public function setSessionId(?string $sessionId): static
    {
        $this->context->sessionId = $sessionId;

        return $this;
    }

    public function addTags(string ...$tags): static
    {
        $tags = array_filter(array_map(trim(...), $tags), static fn(string $tag): bool => $tag !== '');
        $this->context->tags = array_values(array_unique([...$this->context->tags, ...$tags]));

        return $this;
    }

    public function setPublic(bool $public): static
    {
        $this->context->public = $public;

        return $this;
    }
}
