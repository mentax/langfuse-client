# mentax/langfuse-client

Framework-agnostic PHP client for [Langfuse](https://langfuse.com):

- **Prompt management**: fetch, compile, create and label prompts, with a persistent
  last-known-good cache so your application keeps working when Langfuse is down.
- **Tracing**: traces and LLM generations (model, parameters, token usage, cost, linked
  prompt version) exported over OTLP, the only trace ingestion path Langfuse v4 accepts
  by default.

It depends only on PSR interfaces. You bring the HTTP client (PSR-18 + PSR-17),
the cache (PSR-6) and the logger (PSR-3).

Requirements: PHP 8.3+, Langfuse v4 (or v3.22+ for tracing).

## Installation

```bash
composer require mentax/langfuse-client
# a PSR-18 client and a PSR-6 cache, for example:
composer require symfony/http-client nyholm/psr7 symfony/cache
```

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

### HTTP client and timeouts

PSR-18 has no per-request timeout, so **set one on the client you pass in**. The
library calls Langfuse inside your request path (prompt refresh, trace flush); without
a timeout a slow Langfuse makes your application slow. A few seconds is a sensible
upper bound. The library does not retry: a failed prompt refresh falls back to the
cache, a failed trace export is logged and dropped.

## Prompts

```php
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

$prompts = $langfuse->cachedPrompts(
    new FilesystemAdapter('langfuse', 0, '/var/shared/cache'), // persistent storage
    ttlSeconds: 600,
    logger: $logger,
);

$prompt = $prompts->get('damageaudit/airbag-photo');            // label "production"
$prompt = $prompts->get('damageaudit/airbag-photo', 'staging'); // another label
$prompt = $prompts->get('damageaudit/airbag-photo', version: 7); // pinned version

$text = $prompt->compile(['documents_count' => 3]);
$model = $prompt->config['model'] ?? 'gemini-2.5-flash-lite';
```

### Cache behaviour

| Situation | Result |
|---|---|
| Cached entry younger than the TTL | Served from cache, Langfuse is not called |
| Entry older than the TTL | Fetched from Langfuse and the cache is updated |
| Fetch fails (network, 5xx, **404 after deletion**) and an entry exists | The cached entry is served and a warning is logged |
| Fetch fails and nothing is cached | The exception is thrown |
| Version-pinned prompt | Fetched once; versions never change |

Cache entries never expire on their own. Use a persistent pool on storage that
survives restarts and deployments. `refresh()` fetches immediately, ignoring the TTL,
and throws instead of falling back; use it to roll out a label change without waiting
for the TTL, or to warm the cache during deployment.

### Strict compilation

`compile()` throws `PromptCompilationException` when the variables you pass differ
from the `{{variables}}` in the prompt, in either direction. If someone edits a prompt
in Langfuse so it no longer matches the calling code, you get an error instead of a
half-filled prompt sent to a model. Variables are substituted in one pass, so a value
containing `{{something}}` is inserted literally.

Chat prompts work the same way, plus message placeholders:

```php
$messages = $chatPrompt->compile(
    ['claim_type' => 'property'],
    ['history' => [new ChatMessage('user', '...'), ['role' => 'assistant', 'content' => '...']]],
);
```

### Managing prompts

`$langfuse->prompts()` is the uncached API client:

```php
$client = $langfuse->prompts();
$v = $client->createText('damageaudit/vin', 'Read the VIN from {{count}} photos.', ['staging'], ['model' => 'gemini-2.5-flash']);
$client->setLabels('damageaudit/vin', $v->version, ['production']); // promote
```

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
    $generation->fail($e);
    throw $e;
}

$trace->end(['passed' => true]);
$tracer->flush();
```

- Nothing is sent until you call `flush()`, or until `batchSize` ended observations
  accumulate. Call `flush()` at the end of each unit of work: on `kernel.terminate`
  in Symfony, after each Messenger message, at the end of a CLI command. Call
  `shutdown()` when a long-running worker stops; it ends observations still open.
- `flush()` and `shutdown()` never throw. A failed export is logged with level
  `error` and the batch is dropped.
- `Tracer::traceIdFromSeed($auditId)` derives a stable trace ID from your own
  identifier, so you can find or score the trace later without storing its ID.
- `Usage::fromGeminiUsageMetadata()` maps Gemini's `usageMetadata`. Thinking tokens
  count as output, since that is how they are billed; they are also reported as
  `output_reasoning_tokens`.

### What gets sent

Langfuse v4 builds a trace from its spans. The trace's input and output are those of
the root span (`startTrace()` / `Trace::end()`). User, session, tags, environment and
release are written on every span. Inputs and outputs that are not strings are sent
as JSON.

**Inputs and outputs are sent in full.** If they contain personal data, decide what to
send before tracing it, and restrict who can access the Langfuse project.

## Development

```bash
composer install
composer check     # php-cs-fixer (dry run), PHPStan (level max), PHPUnit
php examples/smoke-test.php   # end-to-end against a real instance, see the file header
```

## Credits

The prompt API design draws on [dij-digital/langfuse-php](https://github.com/dij-digital/langfuse-php)
(MIT), and the OTLP mapping on [axyr/laravel-langfuse](https://github.com/axyr/laravel-langfuse) (MIT)
and the Langfuse server source.

## License

MIT
