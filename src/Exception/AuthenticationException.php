<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Exception;

/**
 * HTTP 401 or 403: wrong keys, or keys of a different project.
 */
final class AuthenticationException extends ApiException {}
