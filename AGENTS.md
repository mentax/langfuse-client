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
php examples/smoke-test.php               # prompts + tracing against a real instance (.env.smoke)
php examples/smoke-test-experiments.php   # media, datasets, experiment runs, scores
```

Supported PHP: 8.3, 8.4, 8.5 (CI matrix). Code, comments and docs are in English.

`docs/design.md` records the design decisions and the Langfuse v4 contract with the
evidence behind them. Read it before changing transport, caching, media or experiments.

## Things an agent would get wrong

- **Tracing must not break the traced code.** `Tracer::flush()`/`shutdown()` catch
  every `Throwable`, log, and drop the batch. No retries, no blocking sleeps.
  `Json` degrades on unencodable values instead of throwing.
- **Prompt cache entries never expire in the pool.** Freshness is decided by the
  stored `fetchedAt` and the TTL. A failed refresh, including a 404, serves the
  stale entry. Do not add `expiresAfter()` and do not delete entries on 404.
  A null TTL means "never stale"; `refreshAll()` (scheduled job) reports per-label
  failures and must not stop at the first one.
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
- **Experiments on v4**: `POST /api/public/dataset-run-items` writes nothing. A run
  exists only through `langfuse.experiment.*` attributes, and they must be on **every**
  span of the item trace (no server-side propagation). `item.root_observation_id` must be
  the root span ID.
- **Media**:
  - `sha256Hash` is base64 of the raw digest, not hex.
  - After the presigned PUT, the `PATCH /api/public/media/{id}` report is mandatory.
    Without it the media cannot be downloaded or used in a dataset item. Langfuse
    deduplicates only media reported with status 200.
  - A reference string counts only as the entire value of a JSON field.
- Smoke tests (`examples/`) need a real instance and `.env.smoke`; agents with access
  to one should run both after touching transport, media, datasets or experiments.
- `Internal\*` and members marked `@internal` are not public API.
