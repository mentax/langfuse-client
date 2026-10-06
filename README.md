# mentax/langfuse-client

[![CI](https://github.com/mentax/langfuse-client/actions/workflows/ci.yml/badge.svg)](https://github.com/mentax/langfuse-client/actions/workflows/ci.yml)
![PHP](https://img.shields.io/badge/php-8.3%20%7C%208.4%20%7C%208.5-777bb4)
![License](https://img.shields.io/badge/license-MIT-green)

**Manage your LLM prompts in [Langfuse](https://langfuse.com), not in your PHP code, and see what every model call did, cost and which prompt version produced it.**

`mentax/langfuse-client` is a framework-agnostic PHP client for Langfuse:

- **Prompt management.** Fetch prompts by label or version and compile them strictly.
  A persistent cache keeps your application running when Langfuse is not.
- **Tracing.** Record traces and LLM generations: model, parameters, token usage, cost,
  errors and the prompt version used. They are exported over OpenTelemetry (OTLP), the
  only trace ingestion path Langfuse v4 accepts by default. Files sent to the model are
  recorded by reference, so their content stays in your storage.
- **Datasets, experiments and scores.** Build regression datasets from real cases,
  files included (Langfuse Media), run them through your actual code as experiment
  runs, and score the results.

```php
$prompt = $prompts->get('support/answer-ticket');            // label "production", cached
$text   = $prompt->compile(['ticket' => $ticket->body]);      // throws if variables don't match

$generation = $trace->startGeneration('answer', model: 'gemini-2.5-flash', input: $text, prompt: $prompt);
$generation->end($reply, Usage::fromGeminiUsageMetadata($usage)); // tokens, cost, prompt version
$tracer->flush();                                                  // never throws
```

## Why this package

We built it for our own Symfony applications, which call Ai models through a shared
in-house client. Our requirements were simple: move prompts out of the repositories,
let product people iterate on them in Langfuse, and never let Langfuse take production
down. No existing PHP library did all of that, so we wrote one.

### Your application keeps working when Langfuse is down

Once prompts live in Langfuse, Langfuse becomes a runtime dependency. This client is
built around that fact:

- Prompts are cached in any PSR-6 pool, for example files on persistent storage.
  The cache survives restarts and deployments.
- A cached prompt younger than the TTL (10 minutes by default) is served without a
  network call.
- When a refresh fails, the last version that worked is served and a warning is
  logged. That covers a timeout, a 5xx, and a 404 because someone deleted the prompt.
  Cache entries never expire on their own.
- Optionally, Langfuse leaves the request path entirely: a scheduled job refreshes all
  prompts of the project, and the application only reads the cache.
- Tracing never throws into your code. A failed export is logged and dropped: no
  retries, no blocking `sleep()` in your request path.

### Prompt edits cannot silently break your code

`compile()` is strict. If the prompt in Langfuse uses `{{damage_cause}}` and your code
passes `{{cause}}`, you get a `PromptCompilationException`, not a half-filled prompt
sent to a model. The check runs in both directions: missing variables and unexpected
ones. Substitution is single-pass, so user input containing `{{...}}` is never
expanded.

### Traces that actually arrive in Langfuse v4

Langfuse v4 ingests traces through its OpenTelemetry endpoint. The legacy
`/api/public/ingestion` endpoint still accepts scores, but rejects trace and
observation events by default. The client speaks OTLP/HTTP JSON in the exact shape the
Langfuse server parses, which we verified against its source:

- trace input and output are taken from the root span;
- trace-level fields such as user, session, tags and environment are written on every span;
- prompt links are attached to generations, so Langfuse can show metrics per prompt version;
- the scope name is the one Langfuse treats as an SDK, so your metadata isn't flooded
  with raw attributes.

### No framework, no lock-in

Runtime dependencies are PSR interfaces only: PSR-18/17/7 for HTTP, PSR-6 for the
cache, PSR-3 for logging and PSR-20 for the clock. Use it with Symfony, Laravel,
Laminas or plain PHP, and with Symfony HttpClient, Guzzle or any other PSR-18 client.

### Small and strict

- PHPStan at level max with strict rules.
- PHPUnit tests against both the highest and the lowest supported dependency versions.
- CI on PHP 8.3, 8.4 and 8.5.
- No magic and no global state: you construct objects and pass them where they are needed.

## How it compares

PHP libraries for Langfuse we evaluated before writing this one (state as of October 2026):

| | Prompt fetch + compile | Persistent prompt cache | Traces on Langfuse v4 | Token usage on generations | Datasets + media | Framework |
|---|---|---|---|---|---|---|
| **mentax/langfuse-client** | ✅ strict | ✅ last-known-good | ✅ OTLP | ✅ | ✅ | none (PSR) |
| [dropsolid/langfuse-php-sdk](https://gitlab.com/dropsolid/langfuse-php-sdk) 1.3 | ❌ | ❌ | ❌ legacy ingestion (OTLP in 2.0-alpha) | ✅ | ❌ | none |
| [dij-digital/langfuse-php](https://github.com/dij-digital/langfuse-php) 0.2 | ✅ | ❌ | ❌ legacy ingestion | ❌ | ❌ | none |
| [axyr/laravel-langfuse](https://github.com/axyr/laravel-langfuse) 0.4 | ✅ | in-memory only | ✅ OTLP | ✅ | datasets only | Laravel |

All of them are good work and taught us something; see [Inspiration](#inspiration).
Pick axyr if you are on Laravel and want auto-instrumentation of Prism or Laravel AI.
Pick this package if you want prompts that survive outages, or anything that is not
Laravel.

## Installation

```bash
composer require mentax/langfuse-client
# plus a PSR-18 client and a PSR-6 cache, for example:
composer require symfony/http-client nyholm/psr7 symfony/cache
```

Requirements: PHP 8.3+ and Langfuse v3.22+ (needed for OTLP tracing). Developed against Langfuse v4.

## Setup

```php
use Mentax\LangfuseClient\Langfuse;
use Mentax\LangfuseClient\LangfuseConfig;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

$psr18 = new Psr18Client(HttpClient::create(['timeout' => 3]));

$langfuse = new Langfuse(
    new LangfuseConfig('https://langfuse.example.com', 'pk-lf-...', 'sk-lf-...'),
    $psr18, // PSR-18 client
    $psr18, // PSR-17 request factory
    $psr18, // PSR-17 stream factory
);
// or LangfuseConfig::fromEnvironment(): LANGFUSE_HOST, LANGFUSE_PUBLIC_KEY, LANGFUSE_SECRET_KEY
```

> **Set a timeout on your HTTP client.** PSR-18 has no per-request timeout, and the
> library calls Langfuse inside your request path (prompt refresh, trace flush).
> A few seconds is a sensible upper bound.

## Prompts

```php
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

$prompts = $langfuse->cachedPrompts(
    new FilesystemAdapter('langfuse', 0, '/var/shared/cache'), // persistent storage
    ttlSeconds: 600,
    logger: $logger,
);

$prompt = $prompts->get('damageaudit/airbag-photo');             // label "production"
$prompt = $prompts->get('damageaudit/airbag-photo', 'staging');  // another label
$prompt = $prompts->get('damageaudit/airbag-photo', version: 7); // pinned version

$text  = $prompt->compile(['documents_count' => 3]);
$model = $prompt->config['model'] ?? 'gemini-2.5-flash-lite';   // config travels with the version
```

Keeping model and temperature in the prompt's `config` means changing the model is
just a new prompt version, tested and promoted like any other prompt change.

### Cache behaviour

| Situation | Result |
|---|---|
| Cached entry younger than the TTL | Served from cache; Langfuse is not called |
| Entry older than the TTL | Fetched from Langfuse; the cache is updated |
| `ttlSeconds: null`, entry exists | Served from cache, however old; Langfuse is not called |
| Fetch fails (network, 5xx, 404 after deletion), entry exists | Cached entry served, warning logged |
| Fetch fails, nothing cached | Exception thrown |
| Version-pinned prompt | Fetched once; versions never change |

`refresh()` fetches one prompt immediately and throws instead of falling back. Call
it in your deployment to warm the cache and fail the deploy when a prompt is missing.
You can also call it to roll out a label change without waiting for the TTL.

### Refreshing all prompts from a scheduled job

With a TTL, the first request after the TTL expires calls Langfuse, and with a
timeout set, waits for it. With `ttlSeconds: null`, the application never calls Langfuse
for a prompt it has cached. A scheduled job refreshes the whole project instead:

```php
// bin/refresh-prompts.php
$prompts = $langfuse->cachedPrompts(
    new FilesystemAdapter('langfuse', 0, '/var/shared/cache'), // the application's pool
    ttlSeconds: null,
    logger: $logger,
);

$report = $prompts->refreshAll($langfuse->prompts()->list());

foreach ($report->failed as $failure) {
    fwrite(STDERR, sprintf("%s (%s): %s\n", $failure['name'], $failure['label'], $failure['reason']));
}
exit($report->isComplete() ? 0 : 1);
```

```cron
*/5 * * * * www-data php /srv/app/bin/refresh-prompts.php
```

The application uses the same pool with `ttlSeconds: null`, and calls `get()` as before.

**Which prompts are refreshed.** `list()` returns every prompt of the Langfuse project
the API keys belong to. `refreshAll()` fetches every label of each of them, `latest`
included, and writes it to the cache. Both filters are optional:

```php
$prompts->refreshAll($client->list(tag: 'crm'));                     // only prompts tagged "crm"
$prompts->refreshAll($client->list(), labels: ['production']);       // only the labels your code uses
$prompts->refreshAll($client->list(label: 'production'), labels: ['production']);
```

Use the tag filter when several applications share one project. Without it, each
application also caches the others' prompts; this is harmless, but unnecessary.

**What the report contains.** `$report->refreshed` lists
`['name' => ..., 'label' => ..., 'version' => ...]` for every label written to the
cache. `$report->failed` lists `['name' => ..., 'label' => ..., 'reason' => ...]` for
labels that could not be fetched or stored. Each failure is also logged as an error.
A failure does not stop the job: the other labels are still refreshed, and the
application keeps serving the previous entry of the failed one.

Things worth knowing:

- **The job and the application must share the cache.** Same storage, same pool
  namespace, same `keyPrefix`. A `FilesystemAdapter` on local disk is per server, so
  with several web servers run the job on each of them, or use shared storage or Redis.
- **Monitor the job.** With a null TTL nothing else refreshes the prompts. If the job
  stops, the application keeps serving old versions, and nothing in the application
  reports it. Alert on a non-zero exit code.
- **Cache misses still call Langfuse.** A prompt that is not cached yet, for example
  right after a deployment to an empty cache, is fetched on first use. To avoid that,
  run the job as a deployment step, before traffic arrives.
- **Cost.** Langfuse has no bulk endpoint for prompt content, so one run makes one
  request per prompt label, plus one per 100 prompts for the list.
- **Deleted prompts.** A prompt deleted in Langfuse disappears from the list, and its
  cache entry stays in use, the same as with a TTL.
- **Label changes** reach the application on the next run. Call `refresh()` for an
  urgent rollout.

In a Symfony application the job is a console command, scheduled by cron or
Symfony Scheduler:

```php
#[AsCommand('app:langfuse:refresh-prompts')]
final class RefreshPromptsCommand extends Command
{
    public function __construct(
        private readonly Langfuse $langfuse,
        private readonly CachedPromptProvider $prompts, // the service the application uses
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->prompts->refreshAll($this->langfuse->prompts()->list());
        $output->writeln(sprintf('%d refreshed, %d failed', count($report->refreshed), count($report->failed)));

        return $report->isComplete() ? Command::SUCCESS : Command::FAILURE;
    }
}
```

### Chat prompts and placeholders

```php
use Mentax\LangfuseClient\Prompt\ChatMessage;

$messages = $chatPrompt->compile(
    ['claim_type' => 'property'],
    ['history' => [new ChatMessage('user', '...'), ['role' => 'assistant', 'content' => '...']]],
);
```

### Managing prompts from code

`$langfuse->prompts()` is the uncached API client, useful for migration scripts and CI:

```php
$client = $langfuse->prompts();
$version = $client->createText(
    'damageaudit/vin',
    'Read the VIN from {{count}} photos.',
    labels: ['staging'],
    config: ['model' => 'gemini-2.5-flash'],
    commitMessage: 'Import from repository',
);
$client->setLabels('damageaudit/vin', $version->version, ['production']); // promote

foreach ($client->list(tag: 'damageaudit') as $prompt) {                  // PromptMetadata
    printf("%s (%s): versions %s, labels %s\n", $prompt->name, $prompt->type->value,
        implode(',', $prompt->versions), implode(',', $prompt->labels));
}
```

`list()` returns metadata only (name, type, versions, labels, tags), page by page.
Fetch the content with `get()`.

## Tracing

```php
use Mentax\LangfuseClient\Tracing\Usage;

$tracer = $langfuse->tracer(environment: 'production', release: '2.3.0', logger: $logger);

$trace = $tracer->startTrace('audit-rule', input: ['claim' => $claimId], userId: $userId, tags: ['damageaudit']);

$generation = $trace->startGeneration(
    'airbag-photo',
    model: 'gemini-2.5-flash-lite',
    input: $text,
    modelParameters: ['temperature' => 0],
    prompt: $prompt, // links the generation to this prompt version
);
try {
    $response = $gemini->generate($text);
    $generation->end($response->text, Usage::fromGeminiUsageMetadata($response->usageMetadata));
} catch (Throwable $e) {
    $generation->fail($e); // level ERROR, exception message as status
    throw $e;
}

$trace->end(['passed' => true]);
$tracer->flush();
```

- **When data is sent.** Nothing goes out until `flush()`, or until `batchSize` ended
  observations accumulate. Call `flush()` at the end of each unit of work:
  `kernel.terminate` in Symfony, after each Messenger message, at the end of a CLI
  command. Call `shutdown()` when a long-running worker stops; it ends observations
  that are still open.
- **Spans and events.** `startSpan()` for steps such as retrieval or parsing,
  `event()` for points in time. Both can be nested under any observation.
- **Stable trace IDs.** `Tracer::traceIdFromSeed($auditId)` derives the trace ID from
  your own identifier, so you can find or score the trace later without storing it.
- **Gemini token usage.** `Usage::fromGeminiUsageMetadata()` maps Gemini's
  `usageMetadata`. Thinking tokens count as output, since that is how they are billed.
  For other providers, build a `Usage` with `input`, `output` and `total`.

**Inputs and outputs are sent in full.** If they contain personal data, decide what
to trace, and restrict who can access the Langfuse project.

**Text that is not valid UTF-8** (for example Windows-1250 data, or binary bytes in an
exception message) is sent with the invalid bytes replaced by `�`. It does not make
the export fail.

### Files sent to the model: reference, don't copy

In production, record *which* files a call used, not the files themselves:

```php
use Mentax\LangfuseClient\Tracing\FileReference;

$generation->attachFile(
    FileReference::fromLocalFile($path, id: (string) $document->getId(), url: $documentViewerUrl),
);
```

The observation's metadata gets `attachments: [{id, name, mimeType, url, sha256, size}]`.
The bytes stay in your storage, behind your access control. Point `url` at a page
of your application that checks permissions, never at a public link.
`FileReference::toMarkdown()` renders an inline image or a link if you want the file
visible inside an input or output text.

## Datasets and experiments

Regression tests for prompts: a dataset of real cases with expected results, and
experiment runs that execute your actual code against it. Two things happen in one
workflow:

1. **Curate.** Copy selected cases into a dataset, files included. Upload the files to
   Langfuse Media so the dataset is a frozen snapshot that does not depend on
   production storage.
2. **Run.** Execute each item through your real code path (same files, same parser),
   record it as an experiment run, and score the result. Compare runs side by side in
   the Langfuse UI.

```php
use Mentax\LangfuseClient\Media\MediaTarget;
use Mentax\LangfuseClient\Tracing\ExperimentRun;

$datasets = $langfuse->datasets();
$dataset = $datasets->createDataset('damageaudit/airbag-photo');

// 1. Curate: upload first (the item may not exist yet), then write the item
$itemId = 'airbag-' . $auditId;
$photo = $langfuse->media()->uploadFile($photoPath, MediaTarget::datasetItem($dataset->id, $itemId));
$datasets->upsertItem(
    $dataset->name,
    input: ['documents_count' => 1, 'photo' => $photo], // a reference must be the whole value
    expectedOutput: ['result' => true],
    id: $itemId,
    sourceTraceId: $productionTraceId,
);

// 2. Run
$run = new ExperimentRun($dataset->id, 'prompt v8 / gemini-3.5-flash', metadata: ['promptVersion' => 8]);
foreach ($datasets->items($dataset->name) as $item) {
    $trace = $tracer->startExperimentTrace($run, $item);
    $photoBytes = $langfuse->media()->download($item->mediaReferences()['input.photo']);

    $result = $airbagRule->run($photoBytes, $trace);  // your code, traced as usual
    $trace->end($result);

    $langfuse->scores()->create('correct', $result === $item->expectedOutput, traceId: $trace->traceId());
}
$tracer->flush();
```

Because the dataset items carry Langfuse media references, the same dataset also works
for experiments started from the Langfuse UI, where Langfuse passes the files to the
model itself.

Things worth knowing:

- Langfuse deduplicates media by content hash, so the same photo in many items is stored once.
- A media reference is recognised only as the entire value of a JSON field.
  A `MediaReference` object can be placed in input, output or a dataset item
  directly; it is written as its `@@@langfuseMedia:...@@@` string.
- `items()` returns active items only; archived ones are skipped.
- In Langfuse v4 an experiment run is defined by the traces that belong to it; there is
  nothing to create up front. `ExperimentRun` derives a stable ID from dataset and name,
  so reusing a name adds to that run. Give each run a new name.

## Scores

```php
$scores = $langfuse->scores();
$scores->create('correct', true, traceId: $trace->traceId());                     // BOOLEAN
$scores->create('quality', 0.8, traceId: $traceId, observationId: $generationId);  // NUMERIC
$scores->create('verdict', 'regression', experimentRunId: $run->id);               // CATEGORICAL
```

Scores are sent immediately, one request each. Pass `id` to make retries idempotent.

## Design

[`docs/design.md`](docs/design.md) explains the decisions behind the library: why OTLP,
how the cache behaves and why, the Langfuse v4 contracts for media and experiments,
and how all of it was verified.

## Inspiration

This package stands on the shoulders of others:

- **[Langfuse](https://github.com/langfuse/langfuse)**: the server source is the
  specification. The OTLP attribute mapping, the `{{variable}}` rules (Unicode names,
  optional whitespace) and chat placeholders follow what the server does, not what
  the docs summarise.
- **[dij-digital/langfuse-php](https://github.com/dij-digital/langfuse-php)**: the shape
  of the prompt API (text/chat prompts, compile, create, label updates, fallbacks).
- **[axyr/laravel-langfuse](https://github.com/axyr/laravel-langfuse)**: showing that OTLP
  is the right transport for PHP, and serving a stale prompt when a refresh fails.
- **[dropsolid/langfuse-php-sdk](https://gitlab.com/dropsolid/langfuse-php-sdk)**: careful
  validation of usage and cost payloads against what Langfuse silently drops. Its ADRs
  are worth reading.
- **The official Python and JS SDKs**: environment variable names, the `production`
  label default, and the "a prompt is a versioned, labelled artefact" model.

No code was copied. The design decisions were informed by reading all of the above.

## Roadmap

- Console commands (prompt check/refresh, dataset curation, experiment runner skeleton)
- Optional Symfony bundle for wiring and `kernel.terminate` flushing

Issues and pull requests are welcome.

## Development

```bash
composer install
composer check                             # php-cs-fixer (dry run), PHPStan (level max), PHPUnit
php examples/smoke-test.php                # prompts and tracing against a real instance
php examples/smoke-test-experiments.php    # media, datasets, experiment runs and scores
```

The smoke tests read credentials of a test project from `.env.smoke` (see `examples/bootstrap.php`).

## License

MIT © Mentax
