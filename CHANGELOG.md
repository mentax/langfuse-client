# Changelog

All notable changes to this project are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the
project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.2.0]

### Added

- `MediaClient`: upload files to Langfuse Media (hash, presigned upload, upload report),
  read metadata and download content. `MediaTarget` for trace or dataset item fields,
  `MediaReference` for the `@@@langfuseMedia:...@@@` reference strings.
- `DatasetClient`: create and read datasets, upsert, read and list dataset items;
  `DatasetItem::mediaReferences()`.
- `ExperimentRun` and `Tracer::startExperimentTrace()`: traces of dataset items are
  recorded as experiment runs through `langfuse.experiment.*` span attributes, which is
  how Langfuse v4 builds runs.
- `ScoreClient`: numeric, boolean, categorical and text scores on traces, observations,
  sessions and experiment runs.
- `FileReference` and `Observation::attachFile()`: record files sent to a model by
  reference (ID, URL, hash) without uploading them.
- `examples/smoke-test-experiments.php`, shared `examples/bootstrap.php`.

## [0.1.0]

### Added

- Prompt API client: get by label or version, create text and chat prompts, set labels.
- `TextPrompt` and `ChatPrompt` with strict `{{variable}}` compilation and chat message placeholders.
- `CachedPromptProvider`: PSR-6 cache with TTL refresh and last-known-good fallback.
- Tracer with traces, spans, generations and events, exported as OTLP/HTTP JSON to
  `/api/public/otel/v1/traces`; export failures are logged, never thrown.
- `Usage::fromGeminiUsageMetadata()`.
