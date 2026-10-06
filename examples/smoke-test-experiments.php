<?php

declare(strict_types=1);

/**
 * End-to-end check of media, datasets, experiment runs and scores against a real
 * Langfuse instance. Credentials: see examples/bootstrap.php (.env.smoke).
 *
 *   php examples/smoke-test-experiments.php
 *
 * Creates the dataset "mentax-langfuse-client/smoke-test", adds one item with two
 * files uploaded to Langfuse Media, runs it as an experiment item with a score, and
 * reads everything back.
 */

use Mentax\LangfuseClient\Media\MediaTarget;
use Mentax\LangfuseClient\Tracing\ExperimentRun;
use Mentax\LangfuseClient\Tracing\Usage;

require __DIR__ . '/bootstrap.php';

$smoke = Smoke::create();
$langfuse = $smoke->langfuse;
printf("Langfuse: %s\n", $smoke->config->host);
$startedAt = gmdate('Y-m-d\TH:i:s\Z', time() - 60);

// 1. Dataset and media
$dataset = $langfuse->datasets()->createDataset('mentax-langfuse-client/smoke-test', 'Smoke test of mentax/langfuse-client');
printf("Dataset %s (%s)\n", $dataset->name, $dataset->id);

$itemId = 'smoke-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));

// A 1x1 JPEG: identical in every run, so from the second run on Langfuse deduplicates it.
$photo = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=', true);
if ($photo === false) {
    throw new RuntimeException('Invalid embedded JPEG.');
}
// Unique per run, so the presigned upload path runs every time.
$note = sprintf("Smoke test note for %s\n", $itemId);

$media = $langfuse->media();
$photoRef = $media->upload($photo, 'image/jpeg', MediaTarget::datasetItem($dataset->id, $itemId));
$noteRef = $media->upload($note, 'text/plain', MediaTarget::datasetItem($dataset->id, $itemId));
printf("Uploaded %s and %s\n", $photoRef->mediaId, $noteRef->mediaId);

$langfuse->datasets()->upsertItem(
    $dataset->name,
    input: ['documents_count' => 1, 'photo' => (string) $photoRef, 'note' => (string) $noteRef],
    expectedOutput: ['result' => true],
    metadata: ['source' => 'smoke-test'],
    id: $itemId,
);
$item = $langfuse->datasets()->getItem($itemId);
$downloaded = $media->download($photoRef);
$downloadedNote = $media->download($noteRef);
printf("Item %s read back with %d media references\n", $item->id, count($item->mediaReferences()));

// 2. Experiment run: one item, one generation, one score
$run = new ExperimentRun($dataset->id, 'smoke ' . gmdate('Y-m-d H:i:s'), 'Smoke test run', ['promptVersion' => 1]);
$tracer = $langfuse->tracer(environment: 'smoke-test', logger: $smoke->logger);
$trace = $tracer->startExperimentTrace($run, $item);
$generation = $trace->startGeneration('describe-damage', 'gemini-2.5-flash-lite', 'Describe the photo.');
$generation->end(['result' => true], new Usage(input: 30, output: 5, total: 35));
$trace->end(['result' => true]);
$tracer->flush();
if ($smoke->logger->errors > 0) {
    fwrite(STDERR, "FAIL: trace export failed.\n");
    exit(1);
}
$langfuse->scores()->create('correct', true, traceId: $trace->traceId(), environment: 'smoke-test');
printf("Run %s (%s), trace %s\n", $run->name, $run->id, $trace->traceId());

// 3. Read the experiment item back (ingestion is asynchronous)
$experimentItems = [];
$waited = 0;
while ($waited < 90) {
    sleep(3);
    $waited += 3;
    $response = $smoke->get('/api/public/experiment-items', [
        'experimentId' => $run->id,
        'fromStartTime' => $startedAt,
        'fields' => 'core,dataset,scores',
    ]);
    $data = $response['data'] ?? [];
    $experimentItems = is_array($data) ? $data : [];
    $first = $experimentItems[0] ?? null;
    if (is_array($first) && is_array($first['scores'] ?? null) && $first['scores'] !== []) {
        break;
    }
}
printf("Read back %d experiment items after ~%ds\n", count($experimentItems), $waited);

$experimentItem = is_array($experimentItems[0] ?? null) ? $experimentItems[0] : [];
$encoded = json_encode($experimentItem);
$json = $encoded === false ? '' : $encoded;
$references = $item->mediaReferences();

Smoke::report([
    'dataset item stored with both media references' => isset($references['input.photo'], $references['input.note']),
    'photo downloads byte-identical' => $downloaded === $photo,
    'note downloads byte-identical' => $downloadedNote === $note,
    'experiment item visible in the run' => count($experimentItems) === 1,
    'experiment item points at the dataset item' => str_contains($json, $itemId),
    'experiment item belongs to the trace' => str_contains($json, $trace->traceId()),
    'score attached' => str_contains($json, '"correct"'),
], ['experimentItems' => $experimentItems, 'mediaReferences' => array_map('strval', $references)]);
