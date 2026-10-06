<?php

declare(strict_types=1);

/**
 * End-to-end check against a real Langfuse instance.
 *
 * Put the credentials of a test project into .env.smoke (gitignored) next to composer.json:
 *
 *   LANGFUSE_HOST=http://localhost:3000
 *   LANGFUSE_PUBLIC_KEY=pk-lf-...
 *   LANGFUSE_SECRET_KEY=sk-lf-...
 *
 * then run: php examples/smoke-test.php (see also smoke-test-experiments.php)
 *
 * It adds a version to the text prompt "mentax-langfuse-client/smoke-test" (label
 * "smoke-test"), reads it back through the cache, refreshes a TTL-less cache from the
 * prompt list (scheduled-job mode), sends one trace with a generation
 * linked to that prompt, then reads the trace back from the API and checks every field.
 * Exit code 0 means all checks passed.
 */

use Mentax\LangfuseClient\Prompt\TextPrompt;
use Mentax\LangfuseClient\Tracing\Usage;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

require __DIR__ . '/bootstrap.php';

$smoke = Smoke::create();
$langfuse = $smoke->langfuse;
$logger = $smoke->logger;
printf("Langfuse: %s\n", $smoke->config->host);

// 1. Prompts
$name = 'mentax-langfuse-client/smoke-test';
$created = $langfuse->prompts()->createText(
    $name,
    'Describe the damage in {{count}} photos of {{object}}.',
    labels: ['smoke-test'],
    config: ['model' => 'gemini-2.5-flash-lite', 'temperature' => 0],
    commitMessage: 'mentax/langfuse-client smoke test',
);
printf("Created prompt %s version %d\n", $created->name, $created->version);

$prompts = $langfuse->cachedPrompts(new FilesystemAdapter('langfuse-smoke', 0, sys_get_temp_dir() . '/langfuse-smoke'), logger: $logger);
$prompts->refresh($name, 'smoke-test');
$prompt = $prompts->get($name, 'smoke-test');
if (!$prompt instanceof TextPrompt) {
    throw new RuntimeException(sprintf('Expected a text prompt, got %s.', $prompt->type()->value));
}
$text = $prompt->compile(['count' => 3, 'object' => 'a car']);
printf("Compiled v%d: %s\n", $prompt->version, $text);

// Scheduled-job mode: no TTL, the whole cache refreshed from the prompt list.
$scheduledCache = $langfuse->cachedPrompts(new FilesystemAdapter('langfuse-smoke-cron', 0, sys_get_temp_dir() . '/langfuse-smoke'), ttlSeconds: null, logger: $logger);
$report = $scheduledCache->refreshAll($langfuse->prompts()->list(label: 'smoke-test'), labels: ['smoke-test']);
printf("refreshAll: %d refreshed, %d failed\n", count($report->refreshed), count($report->failed));

// 2. Tracing
$tracer = $langfuse->tracer(environment: 'smoke-test', release: 'local', logger: $logger);
$trace = $tracer->startTrace('smoke-test', input: ['photos' => 3], userId: 'smoke-user', tags: ['smoke-test']);
$generation = $trace->startGeneration('describe-damage', 'gemini-2.5-flash-lite', $text, ['temperature' => 0], $prompt);
usleep(150_000);
$generation->end(
    ['description' => 'Dented front bumper.'],
    Usage::fromGeminiUsageMetadata(['promptTokenCount' => 42, 'candidatesTokenCount' => 7, 'thoughtsTokenCount' => 0, 'totalTokenCount' => 49]),
);
$trace->event('checkpoint', ['step' => 'done']);
$trace->end(['passed' => true]);
$tracer->flush();

if ($logger->errors > 0) {
    fwrite(STDERR, "FAIL: trace export failed, see the log above.\n");
    exit(1);
}
printf("Sent trace %s\n", $trace->traceId());

// 3. Read back. Ingestion is asynchronous, so poll for a while.
$observations = [];
$waited = 0;
while ($waited < 60) {
    sleep(2);
    $waited += 2;
    $response = $smoke->get('/api/public/v2/observations', [
        'traceId' => $trace->traceId(),
        'fields' => 'core,basic,io,model,usage,prompt,trace_context',
    ]);
    $data = $response['data'] ?? [];
    $observations = is_array($data) ? $data : [];
    if (count($observations) >= 3) {
        break;
    }
}
printf("Read back %d observations after ~%ds\n", count($observations), $waited);

$byName = [];
foreach ($observations as $observation) {
    if (is_array($observation) && is_string($observation['name'] ?? null)) {
        $byName[$observation['name']] = $observation;
    }
}
$root = $byName['smoke-test'] ?? [];
$gen = $byName['describe-damage'] ?? [];
$event = $byName['checkpoint'] ?? [];

$contains = static function (mixed $value, string $needle): bool {
    $json = json_encode($value);

    return $json !== false && str_contains($json, $needle);
};
$number = static fn(mixed $value): ?int => is_numeric($value) ? (int) $value : null;
$usage = is_array($gen['usageDetails'] ?? null) ? $gen['usageDetails'] : [];

Smoke::report([
    'refreshAll complete' => $report->isComplete(),
    'refreshAll stored the new version' => in_array(['name' => $name, 'label' => 'smoke-test', 'version' => $created->version], $report->refreshed, true),
    'root span exists' => $root !== [],
    'generation exists' => $gen !== [],
    'event exists' => $event !== [],
    'generation type is GENERATION' => ($gen['type'] ?? null) === 'GENERATION',
    'event type is EVENT' => ($event['type'] ?? null) === 'EVENT',
    'generation parent is the root span' => $root !== [] && ($gen['parentObservationId'] ?? null) === ($root['id'] ?? false),
    'root input is the trace input' => $contains($root['input'] ?? null, 'photos'),
    'root output is the trace output' => $contains($root['output'] ?? null, 'passed'),
    'generation model' => ($gen['model'] ?? $gen['providedModelName'] ?? null) === 'gemini-2.5-flash-lite',
    'generation usage input=42' => $number($usage['input'] ?? null) === 42,
    'generation usage output=7' => $number($usage['output'] ?? null) === 7,
    'generation prompt name' => ($gen['promptName'] ?? null) === $name,
    'generation prompt version' => $number($gen['promptVersion'] ?? null) === $prompt->version,
    'environment' => ($gen['environment'] ?? null) === 'smoke-test',
    'user id' => ($gen['userId'] ?? null) === 'smoke-user',
    'tags' => in_array('smoke-test', is_array($gen['tags'] ?? null) ? $gen['tags'] : [], true),
    'release' => ($gen['release'] ?? null) === 'local',
], $observations);
