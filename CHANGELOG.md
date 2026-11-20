# Changelog

All notable changes to this PHP port of the SpaceXSI TypeScript SDK are documented here. Version numbers follow `@xai-official/sdk`.

## [0.2.9] - 2026-11-17

### Changed

- Removed the retired `grok-imagine-image-quality` id from `KNOWN_IMAGE_MODEL_IDS`. The public models catalog no longer lists it after its November 2, 2026 retirement. Any other string is still accepted as an image model id, so existing calls run as before. Use `grok-imagine-image-2.0` for new work.

### Fixed

- Streaming over SSE keeps the fields of one event together when the server ends lines with `\r\n`. A single `\r\n` was read as a blank line, so `event:` was split from its `data:` (the event name was dropped from `type`) and a `data:` value spread over several lines was parsed line by line as unknown events. Bare `\r` and `\n` line endings are unchanged.

## [0.2.8] - 2026-11-03

### Added

- `$response->watermarked` and `$stream->watermarked` tell you whether the API watermarked the text output of `responses->create()`. Each is `true` when the response has the header `x-watermark: true`, and `false` when the header is missing or has any other value. `$stream->watermarked` is available as soon as `create()` returns, and with `retryBeforeOutput`, both use the headers of the attempt that produced the response. Watermarking is on by default for supported models from November 7, 2026.

## [0.2.7] - 2026-10-29

### Added

- `voice->transcribe()` takes `webm`, `avi`, and `mov` for `audio_format`. A `Blob` without a name is sent as `audio.avi` or `audio.mov` when its MIME type is `video/x-msvideo`, `video/avi`, `video/vnd.avi`, or `video/quicktime`, or when it has no MIME type and its first bytes show AVI or QuickTime. QuickTime files were sent as `audio.mp4` before, which the API also reads.
- `guidelines` on `responses->create()` takes the `cyber` category and the `allow` behavior. The API accepts `allow` only with `cyber`, and `decline` only with `nsfw`, `violence`, and `profanity`, and rejects other pairs with `400`. `cyber` must be enabled for the API key, and the API answers `403` when it isn't.
- `incomplete_details.reason` can be `content_filter`.

### Changed

- Updated the documented API behavior to the current spec. The spec also adds an `ImageBackground` schema that no endpoint uses yet, so it changes no request or response.

## [0.2.6] - 2026-10-28

### Changed

- **Behavior change:** `timeout`, `idleTimeout`, and the `timeout` and `interval` of `videos->wait()` and `batches->wait()` are capped at 24 hours, so none of them waits without a limit. A longer value means 24 hours, and so does a `timeout` or `idleTimeout` of `0` or less, which used to mean no limit. A `wait()` timeout of `0` or less still expires at once, and an `interval` of `0` or less still polls again without a pause. A `wait()` that times out throws `TimeoutError`, so call it again to wait longer.
- Renamed the client class from `SpaceXAI` to `SpaceXSI`. `SpaceXAI` is still available as a deprecated alias of the same class, so `new SpaceXAI()`, `instanceof SpaceXAI`, and a `SpaceXAI` type hint keep working. Replace `SpaceXAI` with `SpaceXSI`.
- Client error messages start with `SpaceXSI:` instead of `SpaceXAI:`.
- The README, package description, and doc comments say SpaceXSI.

### Fixed

- With `retryBeforeOutput`, a streamed `responses->create()` whose connection drops after the `200` headers but before the first byte of the event stream is retried, like a connection that fails before the headers. It used to throw `APIConnectionError` after one request, while a drop after the first byte was retried. An idle or per-attempt timeout, or a caller abort, before the first byte still fails the call, and the default without `retryBeforeOutput` is unchanged.
- `INF`, or a value of more than about 24.8 days, no longer makes `timeout`, `idleTimeout`, or a `wait()` timeout expire at once, or a `wait()` interval poll on the next tick. These values now mean 24 hours. `NAN` counts as `0` for all of these options, so a `timeout` of `NAN` now means 24 hours instead of timing out at once.

## [0.2.5] - 2026-10-23

### Changed

- **Breaking:** `guidelines` on `responses->create()` takes the API's new categories. `category` is `nsfw`, `violence`, or `profanity`, and `behavior` is `decline`, the only behavior at this release. `sexual` is renamed to `nsfw`, and the `self_harm` and `illicit` categories and the `offer_support` behavior are removed. The API rejects the old values with `422`, so replace `sexual` with `nsfw` and remove guidelines that use `self_harm`, `illicit`, or `offer_support`. `0.2.7` adds `cyber` and `allow` on top of this set.
- Updated the documented API behavior to the current spec. A request whose `guidelines` can't be applied, such as one to a multi-agent model or one that sets `stop`, fails with `400`. `temperature` above 1 is sampled at 1, and `top_p` above the model's default, 0.95 for Grok models, is sampled at that default. The documented `top_p` default is now 0.95, and a response's `temperature` and `top_p` are the values the request sent, or the model's defaults when it omitted them. The SDK still sends the values you set. Uploads to Azure Blob SAS URLs in image `output.upload_urls` and video `output.upload_url` also send `x-ms-blob-type: BlockBlob`, and video uploads send `Content-Type: video/mp4`.
- The spec now allows up to 20 handles in `allowed_x_handles` and `excluded_x_handles` for the `x_search` tool, up from 10, and the `xSearch()` doc comment gives the limit.

## [0.2.4] - 2026-10-17

### Added

- `aspect_ratio` for `videos->generate()` accepts `21:9` and `5:2`.
- `KNOWN_REALTIME_MODEL_IDS` includes `grok-voice-3.0` and `grok-voice-2.0`. `grok-voice-latest` points to `grok-voice-3.0`. `grok-voice-think-fast-2.0` is still accepted; a session that asks for it reports `grok-voice-2.0`.
- `guidelines` on `responses->create()`: per-category instructions for model output. Each entry is `['type' => 'policy', 'category' => ..., 'behavior' => ...]`, where `category` is `sexual`, `violence`, `self_harm`, `illicit`, or `profanity` and `behavior` is `decline` (any category) or `offer_support` (`self_harm` only). Each category may appear at most once.

### Changed

- Live search has been removed. `search_parameters` on a Responses request fails with HTTP 410. A tool with `type` `live_search` is rejected. `web_search_options` is the chat-completions form of the same removal. Use `webSearch()` and `xSearch()`.
- The API accepts up to 1000 tools on a request.

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
