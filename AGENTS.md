# AGENTS.md

Public PHP library `mentax/langfuse-client` (MIT, PSR-4 `Mentax\LangfuseClient\` → `src/`):
Langfuse prompt management with a persistent cache, and OTLP tracing. Runtime
dependencies are PSR interfaces only; never add a framework or HTTP-client dependency
to `require`.

## Commands

```bash
composer check                 # all three below
vendor/bin/php-cs-fixer fix    # PER-CS 2.0
vendor/bin/phpstan analyse     # level max + strict rules, must stay at 0 errors
vendor/bin/phpunit
php examples/smoke-test.php    # needs LANGFUSE_HOST/PUBLIC_KEY/SECRET_KEY; talks to a real instance
```

Supported PHP: 8.3, 8.4, 8.5 (CI matrix). Code, comments and docs are in English.

## Things an agent would get wrong

- **Tracing must not break the traced code.** `Tracer::flush()`/`shutdown()` catch
  every `Throwable`, log, and drop the batch. No retries, no blocking sleeps.
  `Json` degrades on unencodable values instead of throwing.
- **Prompt cache entries never expire in the pool.** Freshness is decided by the
  stored `fetchedAt` and the TTL. A failed refresh, including a 404, serves the
  stale entry. Do not add `expiresAfter()` and do not delete entries on 404.
- **Strict `compile()` is intended.** Missing *and* unexpected variables throw.
  Substitution is single-pass (`preg_replace_callback`); never switch to
  `str_replace`, which re-expands values.
- **Langfuse v4 OTLP contract** (verified against the Langfuse server source):
  - Trace = root span; trace input/output come from the root span's
    `langfuse.observation.input/output`, not `langfuse.trace.input`.
  - Trace-level fields (user, session, tags, environment, release) are written on
    every span.
  - IDs are lowercase hex (32 for traces, 16 for spans).
  - Objects are JSON strings in `stringValue`. Langfuse does not decode `kvlistValue`.
  - The scope name must start with `langfuse-sdk`, otherwise Langfuse copies every
    attribute into metadata.
  - Prompt links apply only to observations of type `generation`.
  - Legacy `/api/public/ingestion` rejects trace events on v4. Do not use it for traces.
- `Internal\*` and members marked `@internal` are not public API.
