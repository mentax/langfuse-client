<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Exception;

/**
 * The request never produced an HTTP response: DNS, connection or timeout failure.
 */
final class TransportException extends LangfuseException {}
