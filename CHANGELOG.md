# Changelog

All notable changes to this project are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the
project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Prompt API client: get by label or version, create text and chat prompts, set labels.
- `TextPrompt` and `ChatPrompt` with strict `{{variable}}` compilation and chat message placeholders.
- `CachedPromptProvider`: PSR-6 cache with TTL refresh and last-known-good fallback.
- Tracer with traces, spans, generations and events, exported as OTLP/HTTP JSON to
  `/api/public/otel/v1/traces`; export failures are logged, never thrown.
- `Usage::fromGeminiUsageMetadata()`.
