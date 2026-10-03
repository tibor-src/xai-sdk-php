# xai-sdk-php

Early PHP port of the experimental [SpaceXAI TypeScript SDK](https://github.com/xai-org/xai-sdk-ts) (`@xai-official/sdk`). The Laravel integration is a separate package: [xai-sdk-laravel](https://github.com/tibor-src/xai-sdk-laravel).

This SDK covers the TypeScript SDK's public surface: text (Responses API), images, video, voice (TTS/STT), files, batches, models, tokenizer, and server-side tools (web search, X search, code execution, MCP, and more).

> **Note:** Both the upstream TypeScript SDK and this PHP port are experimental. APIs may change.

## Requirements

- PHP 8.2+
- Composer

## Installation

### PHP SDK

Require the package from this repository:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/tibor-src/xai-sdk-php"
        }
    ],
    "require": {
        "xai-official/sdk-php": "dev-main"
    }
}
```

Or clone locally and use a path repository:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../xai-sdk-php"
        }
    ],
    "require": {
        "xai-official/sdk-php": "*"
    }
}
```

Set your API key:

```bash
export XAI_API_KEY="xai-..."
```

### Laravel

The Laravel integration (`xai-sdk/laravel`) is not part of this repository. Install it from [xai-sdk-laravel](https://github.com/tibor-src/xai-sdk-laravel).

Publish config (optional):

```bash
php artisan vendor:publish --tag=xai-config
```

Add to `.env`:

```env
XAI_API_KEY=xai-...
```

## Usage

### PHP SDK

```php
use XaiOfficial\Sdk\SpaceXAI;
use XaiOfficial\Sdk\Tools\Tools;

$client = new SpaceXAI(['apiKey' => getenv('XAI_API_KEY')]);

// Chat / Responses API (streams under the hood by default)
$response = $client->responses->create([
    'model' => 'grok-4.6',
    'input' => 'What is the capital of France?',
    'tools' => [Tools::webSearch()],
]);

echo $response->toText();

// Explicit streaming
$stream = $client->responses->create([
    'model' => 'grok-4.6',
    'input' => 'Tell me a joke',
    'stream' => true,
]);

$stream->on('text', fn (string $delta) => print($delta));
$result = $stream->done();

// Images
$images = $client->images->generate([
    'model' => 'grok-imagine-image',
    'prompt' => 'A cat wearing sunglasses',
]);

// Video (async with polling)
$started = $client->videos->generate([
    'model' => 'grok-imagine-video',
    'prompt' => 'Ocean waves at sunset',
]);
$video = $client->videos->wait($started['request_id']);

// Voice
$audio = $client->voice->speak([
    'text' => 'Hello from PHP',
    'voice' => 'eve',
]);
file_put_contents('speech.mp3', $audio->bytes());

// Files, batches, models, tokenizer, account
$models = $client->models->language->list();
$keyInfo = $client->account->apiKey();
```

### Tool helpers

```php
use XaiOfficial\Sdk\Tools\Tools;

$tools = [
    Tools::webSearch(['allowed_domains' => ['wikipedia.org']]),
    Tools::xSearch(['from_date' => '2026-01-01']),
    Tools::codeExecution(),
    Tools::mcp(['server_url' => 'https://mcp.example.com', 'server_label' => 'my-mcp']),
    Tools::imageGeneration(),
    Tools::collectionsSearch(['vector_store_ids' => ['vs_abc']]),
    Tools::toolSearch(),
];
```

### Laravel

```php
use XaiSdk\Laravel\Facades\Xai;

$response = Xai::responses->create([
    'model' => 'grok-4.6',
    'input' => 'Hello from Laravel',
]);

return $response->toText();
```

Or inject the client:

```php
use XaiOfficial\Sdk\SpaceXAI;

public function __construct(private SpaceXAI $xai) {}

public function chat(): string
{
    return $this->xai->responses->create([
        'model' => 'grok-4.6',
        'input' => 'Hi',
    ])->toText();
}
```

## SDK defaults

Matching the TypeScript SDK:

- `store` defaults to `false` (API wire default is `true`)
- When `store` is false, `reasoning.encrypted_content` is automatically included
- `responses->create()` without `stream` uses SSE under the hood and returns the final `ModelResponse`
- Retries: POST creates retry only `429` by default; GET/HEAD also retry `408, 409, 5xx, 529`

## Development

```bash
composer install
composer test
```

## License

Apache-2.0
