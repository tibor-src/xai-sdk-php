# Changelog

All notable changes to this PHP port of the SpaceXAI TypeScript SDK are documented here. Version numbers follow `@xai-official/sdk`.

## [0.2.3] - 2026-10-08

### Added

- `output.upload_urls` on image requests uploads each image to a signed URL that you provide.
- `service_tier` `fast`, which is interchangeable with `priority` and uses a model's fast deployment where it has one.
- `grok-imagine-video-1.5-lite` in `KNOWN_VIDEO_MODEL_IDS`.
- `last_frame` for `videos->generate()`, the image the video ends on. It takes the same inputs as `image`.
- `reference_audios` for `videos->generate()`. Each entry is a preset voice as `['voice_id' => ...]`, a clip as `['url' => ...]`, or a `Blob` or `File`, which the SDK converts to a data URL like `reference_images`.
- `grok-4.20-multi-agent` in `KNOWN_MODEL_IDS`, alongside `grok-4.20-multi-agent-0309`.

### Fixed

- Streams no longer fail with `SSE event exceeds 1048576 characters` when one event is larger than 1 MiB, such as the encrypted reasoning that `grok-4.20-multi-agent` sends for all its agents in one event when `store` is false. Each event now gets the same limit as a JSON response, `maxResponseBodyBytes`.
- `voice->transcribe()` names a `Blob` without a file name after its audio format, such as `audio.mp3`, so the API can tell the format. The format comes from the Blob's MIME type or, when it has none, from its first bytes, which the SDK recognizes for MP3, AAC, WAV, FLAC, Ogg, Opus, M4A, MP4, Matroska, and WebM. A `File` keeps its name, an explicit `audio_format` is sent as before, and `voice->custom->create()` names its upload the same way.
- `stripInvalidSpeechTags()` no longer removes bracketed text that is not a speech tag, such as `[they]` in a quote. Bracketed text counts as a tag when it is a known tag, has a hyphen like `[new-tag]`, or resembles a known inline tag, like `[laff]` or `[laughs]`. Other bracketed words, such as `[music]`, are read aloud, and `checkSpeechText()` does not report them.
- `stripInvalidSpeechTags()` removes markup that is not a speech tag, such as `<citation id="web:23"/>`, which the API would read aloud, and keeps the text it wraps. `checkSpeechText()` reports it.
- `batches->wait()` no longer returns right after a batch is created from `input_file_id`, while the batch reports no requests because it is still loading the file. A batch without requests counts as finished only once it is cancelled or expires.
- `videos->generate()` converts a `Blob` or `File` anywhere in the request to a data URL. A Blob in `last_frame` or `reference_audios` was sent as an empty object.

### Changed

- `maxResponseBodyBytes` also limits each event in a response stream, which had a fixed limit of 1 MiB. Stream events can now be up to 32 MiB by default, and a client that sets a lower `maxResponseBodyBytes` applies it to stream events too.

## [0.2.2] - 2026-10-05

### Fixed

- A `responses->create()` without `stream` now resolves when a `retryBeforeOutput` retry returns JSON, as it does when the first attempt returns JSON, instead of throwing `Streaming response must use text/event-stream`.

## [0.2.1] - 2026-10-02

Initial public PHP port of `@xai-official/sdk` 0.2.1.
