# tibor-src/xai-sdk-php

Unofficial PHP port of the experimental [SpaceXAI TypeScript SDK](https://github.com/xai-org/xai-sdk-ts) (`@xai-official/sdk` 0.2.1).

This package is not published or maintained by xAI. It follows the TypeScript client's resources, request defaults, and error types. PHP method calls return their results directly.

## Install

```bash
composer require tibor-src/xai-sdk-php
```

Requires PHP 8.2 or newer, with the curl, json, and mbstring extensions.

```php
use TiborSrc\XaiSdkPhp\SpaceXAI;

$client = new SpaceXAI([
    'apiKey' => getenv('XAI_API_KEY'),
]);

$response = $client->responses->create([
    'model' => 'grok-4.6',
    'input' => 'Hello',
]);

echo $response->toText();
```

`store` defaults to `false`. When it is false, the client also sends `reasoning.encrypted_content` in `include`. A create call that omits `stream` is sent as a stream and returned as the finished response. Pass `'stream' => false` for one JSON response, or `'stream' => true` for a `ResponseStream`.

```php
$stream = $client->responses->create([
    'model' => 'grok-4.6',
    'input' => 'Hello',
    'stream' => true,
]);

$stream->on('text', function (string $delta): void {
    echo $delta;
});

$done = $stream->done();
```

Pass the previous output back with `$response->toInput()`.

## Resources

The client exposes the same resource groups as the TypeScript SDK:

- `responses` (`create`, `compact`, `get`, `delete`, `inputItems->list`)
- `models` (`list`, `get`, plus `language`, `image`, and `video`)
- `images` (`generate`, `edit`)
- `videos` (`generate`, `edit`, `extend`, `get`, `wait`)
- `files` (`upload`, `list`, `get`, `delete`, `content`, `createPublicUrl`, `revokePublicUrl`)
- `batches` (`create`, `list`, `get`, `cancel`, `results`, `wait`, `requests`)
- `voice` (`speak`, `transcribe`, `list`, `get`, `custom`, `clientSecrets`)
- `tokenizer` (`encode`)
- `account` (`apiKey`)

Tool helpers (`webSearch`, `xSearch`, `codeExecution`, `collectionsSearch`, `mcp`, `imageGeneration`, `toolSearch`) build the same tool objects as the TypeScript SDK. `checkSpeechText` and `stripInvalidSpeechTags` check the speech tags that `voice->speak` accepts.

## License

Apache License 2.0. See `LICENSE` and `NOTICE`.
