<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Exception;

/**
 * Langfuse answered 2xx, but the body is not the JSON shape this library expects.
 */
final class InvalidResponseException extends LangfuseException {}
