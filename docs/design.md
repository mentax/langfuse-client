# Design decisions

Why `mentax/langfuse-client` works the way it does. `README.md` covers usage and
`AGENTS.md` lists the pitfalls when changing the code. This file records the
decisions and the evidence behind them, so they are not re-argued or undone by
accident.

Verified against Langfuse v4.50/4.51 (self-hosted, default settings) in two ways:
by reading the Langfuse server source, and with the smoke tests in `examples/`
run against a live instance.

## 1. Context and goals

The library was built for PHP applications (Symfony and an in-house framework) that
call Gemini on Vertex AI and are moving their prompts out of code repositories into
Langfuse. Three goals shaped it:

1. Prompts live in Langfuse and are fetched at runtime. The application must keep
   working when Langfuse is down.
2. Every model call is traced with model, token usage, cost, errors and the prompt
   version that produced it.
3. Prompt changes are regression-tested on real cases (including image and PDF
   inputs) before they reach production.

## 2. Own library instead of an existing one

We evaluated the PHP libraries available in October 2026:

| Library | Why not |
|---|---|
| `dropsolid/langfuse-php-sdk` 1.3 | Write-only (traces, scores), through the legacy ingestion endpoint that Langfuse v4 rejects for traces. No prompts, no datasets. 2.0-alpha adds OTLP. |
| `dij-digital/langfuse-php` 0.2 | Good prompt API, but no cache. Tracing goes through legacy ingestion, without token usage, one synchronous HTTP call per event, exceptions propagate. No datasets or media. |
| `axyr/laravel-langfuse` 0.4 | Feature-complete, but its API clients use Laravel facades (`Http::`, `Log::`) and require `illuminate/*`. Its prompt cache is in-process memory only. |

Forking was considered: dropsolid for tracing, with prompts ported from dij-digital.
We rejected it once it became clear that dropsolid 1.x tracing does not work on
Langfuse v4 at all (section 4). A fork would have kept almost nothing.

The design borrows ideas from all three and from the Langfuse server source; no code
was copied.

## 3. Dependencies: PSR interfaces only

Runtime dependencies are `psr/http-client`, `psr/http-factory`, `psr/http-message`,
`psr/cache`, `psr/log` and `psr/clock`. The application supplies the HTTP client, the
cache pool and the logger. Consequences:

- The library works with any framework and any PSR-18 client.
- **Timeouts are the caller's responsibility.** PSR-18 has no per-request timeout, so
  configure it on the client you pass in. A few seconds is a sensible bound, because
  the library calls Langfuse inside the request path.
- **There are no retries and no `sleep()` anywhere.** dropsolid's default of 3 retries
  with exponential backoff could block a web request for over a minute. Its in-memory
  circuit breaker resets with every PHP-FPM request, so it does not help either.

## 4. Tracing goes over OTLP, not the ingestion API

**Finding.** Langfuse v4 defaults to `LANGFUSE_MIGRATION_V4_WRITE_MODE=events_only`.
In that mode `POST /api/public/ingestion` rejects trace and observation events and
only processes scores. The only working path for traces is OTLP/HTTP at
`/api/public/otel/v1/traces`. In the Langfuse repository this is visible in
`web/src/env.mjs` (default) and `web/src/pages/api/public/ingestion.ts` (filtering).

The exporter sends OTLP JSON in the shape the Langfuse server parses:

- IDs are lowercase hex: 32 characters for trace IDs, 16 for span IDs. Langfuse
  stores them verbatim and does not decode base64.
- Objects (usage, cost, model parameters, metadata, input, output) are JSON strings
  in `stringValue`. Langfuse does not decode `kvlistValue`.
- Timestamps are Unix nanoseconds as decimal strings.
- **A trace is its root span.** Trace input and output come from the root span's
  `langfuse.observation.input` and `langfuse.observation.output`;
  `langfuse.trace.input` is ignored in v4. Trace-level fields (user, session, tags,
  public, environment, release) are aggregated across spans, so the library writes
  them on every span.
- The scope name starts with `langfuse-sdk`. For any other scope, Langfuse copies every
  span attribute into the observation metadata as well.
- Prompt links (`langfuse.observation.prompt.name` and `.version`) are honoured only
  on observations of type `generation`.
- Headers: `x-langfuse-ingestion-version: 4` and `x-langfuse-sdk-name` / `-version`.
- Environment names must match `^[a-z0-9_-]{1,40}$` and must not start with
  `langfuse`; otherwise Langfuse silently renames them to `default`. The tracer
  rejects invalid names up front.

**Failure policy:** tracing must never break the traced code. `flush()` and
`shutdown()` catch every `Throwable`, log it and drop the batch. Values that cannot
be encoded degrade instead of throwing. Ended observations are buffered and flushed
explicitly (`kernel.terminate`, after each Messenger message, at the end of a CLI
command) or automatically when `batchSize` is reached.

**Gemini usage.** `Usage::fromGeminiUsageMetadata()` maps `output` to
`candidatesTokenCount + thoughtsTokenCount`, because thinking tokens are billed as
output. Thinking and cached tokens are additionally reported as
`output_reasoning_tokens` and `input_cached_tokens`. Langfuse's built-in price table
matched `gemini-2.5-flash-lite` and computed cost from `input` and `output`
(verified live).

## 5. Prompts: persistent last-known-good cache and strict compilation

Moving prompts into Langfuse makes Langfuse a runtime dependency. `CachedPromptProvider`
is built to absorb that:

- Entries live in a PSR-6 pool on persistent storage and **never expire on their own**.
  Freshness is decided by the stored `fetchedAt` against the TTL, 10 minutes by default.
- A fresh entry is served without a network call. A stale entry triggers a fetch.
  When the fetch fails, the stale entry is served and a warning is logged. This
  covers network errors, 5xx responses and **404**: a deleted prompt must not take
  production down.
- With no entry at all, the failure is thrown, so a cold cache fails loudly.
- A version-pinned prompt is fetched once, since versions are immutable.
- `refresh()` fetches immediately and throws instead of falling back. It is meant for
  deploy-time warm-up and for rolling out a label change without waiting for the TTL.

**Strict `compile()`.** Missing variables and unexpected variables both throw
`PromptCompilationException`. A prompt edited in Langfuse so that it no longer matches
the calling code fails visibly instead of sending a half-filled prompt to a model.
Substitution is a single `preg_replace_callback` pass, so a value containing `{{x}}`
is never expanded, unlike `str_replace` chains. Variable names follow the Langfuse
server: a Unicode letter followed by letters, digits or `_`, with optional whitespace
inside the braces.

**Applications keep the response schema in code.** Prompt `config` carries model and
generation parameters, so changing the model creates a new prompt version. The JSON
schema the parser depends on stays in code; if it lived in Langfuse, a prompt edit
could break the parser without any code change.

## 6. Files: reference in production, copy only for datasets

Two needs pull in different directions. Production traces should not duplicate
personal data. Regression datasets must not depend on files that can change or
disappear.

- **Production:** `FileReference` and `Observation::attachFile()` record ID, name, MIME
  type, SHA-256, size and a URL in the observation metadata (`attachments`). The bytes
  stay in the application's storage. The URL should point at the application's own
  document viewer, which enforces its permissions. Langfuse renders http(s) image URLs
  in markdown views; `gs://` and other schemes are not rendered.
- **Datasets:** curated cases are copied into Langfuse Media, which makes the dataset a
  frozen snapshot. Langfuse can then pass the files to the model in UI-started
  experiments, and code-based runners download them from Langfuse rather than from
  production storage.

### Langfuse Media contract (v4)

1. `POST /api/public/media` sends `contentType`, `contentLength`, `sha256Hash` and a
   target. `sha256Hash` is **base64 of the raw SHA-256 digest, not hex**. The target
   is either a trace (`traceId`, `field`, optional `observationId`) or a dataset item
   (`datasetId` (the ID, not the name), `datasetItemId`, `field`), never both. The
   dataset item does not need to exist yet.
2. If the response has an `uploadUrl`, the client `PUT`s the bytes there with
   `Content-Type`, `Content-Length` and `x-amz-checksum-sha256`. Langfuse credentials
   must not be sent to the storage.
3. `PATCH /api/public/media/{id}` reporting the upload result is **mandatory**. Until
   the report says status 200, the media cannot be downloaded, cannot be referenced by
   a dataset item, and is not used for deduplication.
4. A null `uploadUrl` means the same content is already stored (deduplication by hash
   per project). The library skips the upload.
5. References `@@@langfuseMedia:type=…|id=…|source=bytes@@@` count only as the
   **entire value** of a JSON field; embedded in longer text they are ignored.

## 7. Experiments: runs are span attributes

**Finding.** In v4 `events_only` mode, `POST /api/public/dataset-run-items` validates
its input and returns an ID, but writes nothing. A run exists only as traces whose
spans carry `langfuse.experiment.*` attributes. There is no server-side propagation
from the root span, so **every span** of an item's trace needs them:

| Attribute | Value |
|---|---|
| `langfuse.experiment.id` | run ID |
| `langfuse.experiment.name` | run name |
| `langfuse.experiment.dataset.id` | dataset ID |
| `langfuse.experiment.item.id` | dataset item ID |
| `langfuse.experiment.item.root_observation_id` | span ID of the item trace's root span |
| `langfuse.experiment.item.expected_output`, `.description`, `.metadata` | optional |

`Tracer::startExperimentTrace()` sets all of them. `ExperimentRun` derives a stable
16-hex ID from dataset ID and run name, so the same name always maps to the same run.
Callers should give each run a new name.

Scores (`POST /api/public/scores`) attach to exactly one of a trace (optionally an
observation in it), a session, or a run (`datasetRunId`). Experiment views aggregate
item scores by trace ID.

The intended workflow combines both experiment styles on one dataset. Code-based runs
execute the real application path and decide what gets deployed. UI runs let prompt
authors iterate quickly. UI runs only insert files where the prompt has a variable
for them, so their message structure differs from production calls.

## 8. Verification

- **Unit tests** use a fake PSR-18 client and assert the exact wire format (URLs,
  headers, JSON bodies, OTLP shape).
- **Static checks:** PHPStan at level max with strict rules, PER-CS 2.0, and CI on
  PHP 8.3, 8.4 and 8.5 plus a lowest-dependencies job. That job raised the floors
  to `psr/http-factory ^1.1` and `nyholm/psr7 ^1.8.2`, because older versions
  trigger deprecations on PHP 8.4.
- **Smoke tests** against a real instance read everything back through the API:
  - `examples/smoke-test.php` (prompts and tracing, 17 checks);
  - `examples/smoke-test-experiments.php` (media, datasets, runs and scores, 7 checks).

  Re-run both after any Langfuse upgrade: the v4 contract above is young and may move.
