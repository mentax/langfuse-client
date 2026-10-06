<?php

declare(strict_types=1);

/**
 * End-to-end check against a real Langfuse instance.
 *
 *   LANGFUSE_HOST=http://localhost:3000 LANGFUSE_PUBLIC_KEY=pk-lf-... LANGFUSE_SECRET_KEY=sk-lf-... \
 *     php examples/smoke-test.php
 *
 * Creates (or adds a version to) the text prompt "mentax-langfuse-client/smoke-test" with
 * the label "smoke-test", reads it back through the cache, compiles it, and sends one
 * trace with a linked generation. Check the printed trace ID in the Langfuse UI.
 */

use Mentax\LangfuseClient\Langfuse;
use Mentax\LangfuseClient\LangfuseConfig;
use Mentax\LangfuseClient\Prompt\TextPrompt;
use Mentax\LangfuseClient\Tracing\Usage;
use Psr\Log\AbstractLogger;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

require __DIR__ . '/../vendor/autoload.php';

$logger = new class extends AbstractLogger {
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $exception = $context['exception'] ?? null;
        $levelName = is_string($level) ? $level : get_debug_type($level);
        fwrite(STDERR, sprintf("[%s] %s%s\n", $levelName, $message, $exception instanceof Throwable ? ' ' . $exception->getMessage() : ''));
    }
};

$psr18 = new Psr18Client(HttpClient::create(['timeout' => 5]));
$langfuse = new Langfuse(LangfuseConfig::fromEnvironment(), $psr18, $psr18, $psr18);

$name = 'mentax-langfuse-client/smoke-test';
$created = $langfuse->prompts()->createText(
    $name,
    'Describe the damage in {{count}} photos of {{object}}.',
    labels: ['smoke-test'],
    config: ['model' => 'gemini-2.5-flash-lite', 'temperature' => 0],
    commitMessage: 'mentax/langfuse-client smoke test',
);
printf("Created %s version %d\n", $created->name, $created->version);

$prompts = $langfuse->cachedPrompts(new FilesystemAdapter('langfuse-smoke', 0, sys_get_temp_dir() . '/langfuse-smoke'), logger: $logger);
$prompt = $prompts->get($name, 'smoke-test');
if (!$prompt instanceof TextPrompt) {
    throw new RuntimeException(sprintf('Expected a text prompt, got %s.', $prompt->type()->value));
}
$text = $prompt->compile(['count' => 3, 'object' => 'a car']);
printf("Compiled v%d: %s\n", $prompt->version, $text);

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

printf("Sent trace %s\n", $trace->traceId());
