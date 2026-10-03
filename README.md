# tibor-src/xai-sdk-php

Unofficial PHP port of the experimental [SpaceXAI TypeScript SDK](https://github.com/xai-org/xai-sdk-ts) (`@xai-official/sdk` 0.2.1).

This package is not published or maintained by xAI. It follows the TypeScript client's resources, request defaults, and error types. This guide follows the [TypeScript SDK README](https://github.com/xai-org/xai-sdk-ts/blob/main/README.md), with the examples written for PHP. Method calls return their results directly.

Use Grok from PHP with a client built on the SpaceXAI REST API. The SDK has no runtime dependencies beyond PHP's curl, json, and mbstring extensions. It includes streaming, structured output, function tools, image input, image and video generation, file uploads, batch processing, text to speech and transcription, multi-turn conversations, and access to usage and HTTP metadata.

> **Experimental.** The TypeScript SDK this port follows is in early development. It covers the Responses API, image and video generation, the Files, Batch, and Voice APIs, tokenization, and model and account lookup. Its interfaces may change between releases before 1.0. Pin an exact version when upgrading. Report issues with this port in [this repository](https://github.com/tibor-src/xai-sdk-php/issues).

## Requirements

- PHP 8.2 or later
- The curl, json, and mbstring extensions
- A [SpaceXAI API key](https://console.x.ai)

## Installation

```bash
composer require tibor-src/xai-sdk-php
```

## Quickstart

Set your API key in the environment. The client reads `XAI_API_KEY` automatically.

```bash
export XAI_API_KEY="your-api-key"
```

```php
use TiborSrc\XaiSdkPhp\SpaceXAI;

$client = new SpaceXAI();

$response = $client->responses->create([
    'model' => 'grok-4.7',
    'input' => 'Explain why the sky is blue in one sentence.',
]);

echo $response->toText();
```

Keep API keys on the server.

## Streaming

Set `stream` to `true` to receive output as it is generated. Listen for answer text with `on('text')`, then call `done()` for the final response:

```php
$stream = $client->responses->create([
    'model' => 'grok-4.7',
    'input' => 'Write a short story about a curious robot.',
    'stream' => true,
]);

$response = $stream
    ->on('text', function (string $text): void {
        echo $text;
    })
    ->done();

echo "\n{$response->usage->total_tokens} tokens\n";
```

`done()` returns the same response object a request with `stream` set to `false` returns. It throws if the stream fails or closes before the response completes. An error partway through a stream, such as `Service temporarily unavailable`, ends the response and is left as-is, because the model may already have produced output. That also applies to `responses->create()` when `stream` is omitted, which streams under the hood. To retry failures that happen before any output, set [`retryBeforeOutput`](#timeouts-retries-and-cancellation).

Reasoning models think before they answer, and at the default effort a long answer can take minutes to start. For text that streams to a UI, set `reasoning` to `['effort' => 'low']`, or show `reasoning` events while the model thinks.

Besides `text`, `on()` has helper events for the rest of a response:

- `reasoning` for each chunk of reasoning text or reasoning summary
- `tool_call` for each tool call once its arguments are complete, whether your code or SpaceXAI runs it. Check `type` to tell them apart
- `client_tool_call` for each call that your code runs: your function tools and shell commands
- `server_tool_call` for each call to a tool that SpaceXAI runs, such as web search or code execution
- `image` for each finished image generation call, with the base64 image in `result`
- `citation` for each URL citation in the answer
- `json` for structured output: the output parsed so far, each time more text arrives. See [Streaming structured output](#streaming-structured-output)

Each tool call fires once, when its arguments are complete. For your function tools, that is when to run the function, since nothing has run it yet. To show that a call has started before its arguments arrive, listen for `response.output_item.added`.

```php
$response = $stream
    ->on('reasoning', function (string $text): void {
        fwrite(STDERR, $text);
    })
    ->on('text', function (string $text): void {
        echo $text;
    })
    ->on('tool_call', function (array $call): void {
        fwrite(STDERR, "\n{$call['type']} {$call['status']}");
    })
    ->done();
```

`on()` also takes any server-sent event type the SDK knows, such as `response.completed`, and passes the listener the event array. Events the SDK does not recognize arrive as `unknown`, with the original payload in `raw`.

You can also iterate over the stream. After the loop, `done()` returns the final response:

```php
foreach ($stream as $event) {
    if (($event['type'] ?? null) === 'response.function_call_arguments.delta') {
        echo $event['delta'];
    }
}

$response = $stream->done();
echo $response->toText();
```

`$stream->http` has the HTTP status, headers, and request IDs as soon as `create()` returns.

If you stop consuming a stream early, call `$stream->close()` to cancel its response body.

## Multi-turn conversations

Responses are stored only when you opt in. Use `toInput()` to carry the model output, including encrypted reasoning content, into the next turn. Reuse one `prompt_cache_key` across the conversation to improve prompt cache routing:

```php
use TiborSrc\XaiSdkPhp\SpaceXAI;

$client = new SpaceXAI();
$promptCacheKey = 'conversation:' . sprintf(
    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    random_int(0, 0xffff),
    random_int(0, 0xffff),
    random_int(0, 0xffff),
    random_int(0, 0x0fff) | 0x4000,
    random_int(0, 0x3fff) | 0x8000,
    random_int(0, 0xffff),
    random_int(0, 0xffff),
    random_int(0, 0xffff),
);
$input = [
    ['role' => 'user', 'content' => 'My name is Ada. Remember it.'],
];

$first = $client->responses->create([
    'model' => 'grok-4.7',
    'input' => $input,
    'prompt_cache_key' => $promptCacheKey,
]);

$second = $client->responses->create([
    'model' => 'grok-4.7',
    'input' => [
        ...$input,
        ...$first->toInput(),
        ['role' => 'user', 'content' => 'What is my name?'],
    ],
    'prompt_cache_key' => $promptCacheKey,
]);

echo $second->toText();
```

Use a different cache key for each unrelated conversation.

To continue a stored response by ID, opt in to storage:

```php
$first = $client->responses->create([
    'model' => 'grok-4.7',
    'input' => 'My name is Ada. Remember it.',
    'store' => true,
]);

$second = $client->responses->create([
    'model' => 'grok-4.7',
    'input' => 'What is my name?',
    'previous_response_id' => $first->id,
    'store' => true,
]);
```

### Compacting long conversations

Every turn resends the whole conversation, so input tokens grow as it gets longer. Compact the conversation into a single encrypted item with `responses->compact()`, then start the next input with the compacted `output`. Continuing the `toInput()` example:

```php
$compacted = $client->responses->compact([
    'model' => 'grok-4.7',
    'input' => [
        ...$input,
        ...$first->toInput(),
        ['role' => 'user', 'content' => 'What is my name?'],
        ...$second->toInput(),
    ],
]);

$third = $client->responses->create([
    'model' => 'grok-4.7',
    'input' => [
        ...$compacted->output,
        ['role' => 'user', 'content' => 'Spell my name backwards.'],
    ],
    'prompt_cache_key' => $promptCacheKey,
]);

echo $third->toText();
```

Pass `$compacted->output` unchanged and add new turns after it. The conversation must still fit in the model's context window when you compact it. `$compacted->usage` reports the tokens the compaction used and `dropped_message_count`, the number of messages it replaced.

## Image input

Pass an image URL alongside text:

```php
$response = $client->responses->create([
    'model' => 'grok-4.7',
    'input' => [
        [
            'role' => 'user',
            'content' => [
                ['type' => 'input_text', 'text' => 'Describe this image.'],
                [
                    'type' => 'input_image',
                    'image_url' => 'https://example.com/image.jpg',
                    'detail' => 'high',
                ],
            ],
        ],
    ],
]);

echo $response->toText();
```

For a local image, pass a `Blob` or `File` as `image`. The SDK converts it to a data URL before sending the request, and detects JPEG, PNG, or WebP from the bytes when the `Blob` has no MIME type.

```php
use TiborSrc\XaiSdkPhp\Http\Blob;

$image = new Blob(file_get_contents('./image.png'), 'image/png');

$response = $client->responses->create([
    'model' => 'grok-4.7',
    'input' => [
        [
            'role' => 'user',
            'content' => [
                ['type' => 'input_text', 'text' => 'What is in this image?'],
                ['type' => 'input_image', 'image' => $image],
            ],
        ],
    ],
]);
```

## Structured output

Provide a JSON Schema through `text.format`. Call `toJson()` to parse the completed text output.

```php
$response = $client->responses->create([
    'model' => 'grok-4.7',
    'input' => 'Give me a city to visit in Japan.',
    'text' => [
        'format' => [
            'type' => 'json_schema',
            'name' => 'travel_suggestion',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'city' => ['type' => 'string'],
                    'reason' => ['type' => 'string'],
                ],
                'required' => ['city', 'reason'],
                'additionalProperties' => false,
            ],
        ],
    ],
]);

$suggestion = $response->toJson();
```

Validate the result before using it at a trust boundary. The non-throwing `$response->parsed` property is `null` when the output is incomplete or is not valid JSON.

`toJson()` can also take a synchronous [Standard Schema](https://standardschema.dev) validator: an object or array whose `~standard.validate` callable returns `['value' => ...]` or `['issues' => ...]`. It throws if the JSON does not match, listing each problem with its path. Validators that return a promise or a generator are rejected.

### Streaming structured output

When you stream a request with a JSON Schema in `text.format`, the `json` event receives the output parsed so far each time more text arrives. Unfinished strings, arrays, and objects are closed, so you always get a value you can render. As the model writes a podcast script, the event receives values like these:

```php
['title' => 'Why the']
['title' => 'Why the sky is blue', 'lines' => [['speaker' => 'host', 'text' => 'Wel']]]
['title' => 'Why the sky is blue', 'lines' => [['speaker' => 'host', 'text' => 'Welcome back! Today a simple question.'], ['speaker' => 'gu']]]
['title' => 'Why the sky is blue', 'lines' => [['speaker' => 'host', 'text' => 'Welcome back! Today a simple question.'], ['speaker' => 'guest', 'text' => 'Why is the sky blue?']]]
```

A string can stop mid-word, even an enum value such as `gu` on its way to `guest`. A number is left out until it is complete, so you never see `1` for what becomes `12`. In an array, every item before the last one is finished, so you can use each item as soon as the next one starts. This prints each line of the script once it is finished:

```php
$stream = $client->responses->create([
    'model' => 'grok-4.7',
    'input' => 'Write a short two-person podcast script about why the sky is blue.',
    'reasoning' => ['effort' => 'low'],
    'text' => [
        'format' => [
            'type' => 'json_schema',
            'name' => 'podcast_script',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'lines' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'speaker' => ['type' => 'string', 'enum' => ['host', 'guest']],
                                'text' => ['type' => 'string'],
                            ],
                            'required' => ['speaker', 'text'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['title', 'lines'],
                'additionalProperties' => false,
            ],
        ],
    ],
    'stream' => true,
]);

$finished = 0;
$print = function (array $line): void {
    echo "{$line['speaker']}: {$line['text']}\n";
};

$response = $stream
    ->on('json', function (mixed $partial) use (&$finished, $print): void {
        $lines = is_array($partial) ? ($partial['lines'] ?? []) : [];
        while ($finished < count($lines) - 1) {
            $print($lines[$finished++]);
        }
    })
    ->done();

$script = $response->toJson();
foreach (array_slice($script['lines'], $finished) as $line) {
    $print($line);
}
```

To speak each line while the model writes the next one, call `$client->voice->speak()` in place of `$print`. Values from the `json` event are not validated, so read the complete result from the final response with `toJson()`.

The `json` event runs `parsePartialJson()` on the text received so far, and you can call it yourself. It works like `json_decode` on JSON that is cut off partway: it returns what is there so far, with open strings, arrays, and objects closed. It returns `null` when the text holds no value yet or is broken rather than unfinished:

```php
use function TiborSrc\XaiSdkPhp\parsePartialJson;

parsePartialJson('{"title":"Why the'); // ['title' => 'Why the']
parsePartialJson('{"title":"Why the sky is blue","minutes":1'); // ['title' => 'Why the sky is blue']
parsePartialJson('{"title":"Why the sky is blue","minutes":12}'); // ['title' => 'Why the sky is blue', 'minutes' => 12]
parsePartialJson('{"title" "oops"}'); // null
```

Call it when you read the events in a loop, or on text you forward elsewhere:

```php
use function TiborSrc\XaiSdkPhp\parsePartialJson;

$text = '';
foreach ($stream as $event) {
    if (($event['type'] ?? null) === 'response.output_text.delta') {
        $text .= $event['delta'];
        var_export(parsePartialJson($text));
    }
}
```

## Tools

Describe functions with JSON Schema, run the requested function in your application, then return its output to the model:

```php
use TiborSrc\XaiSdkPhp\SpaceXAI;

use function TiborSrc\XaiSdkPhp\isFunctionCall;

$client = new SpaceXAI();
$prompt = 'What is the weather in San Francisco?';
$input = [['role' => 'user', 'content' => $prompt]];
$getWeatherTool = [
    'type' => 'function',
    'name' => 'get_weather',
    'description' => 'Get the current weather for a location.',
    'parameters' => [
        'type' => 'object',
        'properties' => [
            'location' => ['type' => 'string'],
        ],
        'required' => ['location'],
        'additionalProperties' => false,
    ],
];

$getWeather = function (mixed $args): array {
    if (! is_array($args) || ! is_string($args['location'] ?? null)) {
        throw new RuntimeException('get_weather expects a location string');
    }

    return ['location' => $args['location'], 'temperatureC' => 18, 'conditions' => 'sunny'];
};

$response = $client->responses->create([
    'model' => 'grok-4.7',
    'input' => $input,
    'parallel_tool_calls' => false,
    'tools' => [$getWeatherTool],
]);

$call = null;
foreach ($response->output as $item) {
    if (isFunctionCall($item)) {
        $call = $item;
        break;
    }
}
if ($call === null) {
    throw new RuntimeException('The model did not call get_weather');
}
$result = $getWeather(json_decode($call['arguments'], true));

$answer = $client->responses->create([
    'model' => 'grok-4.7',
    'input' => [
        ...$input,
        ...$response->toInput(),
        [
            'type' => 'function_call_output',
            'call_id' => $call['call_id'],
            'output' => json_encode($result),
        ],
    ],
]);

echo $answer->toText();
```

`parallel_tool_calls` set to `false` limits the model to one function call per turn, so this example only has to handle one. By default the model can ask for several at once, which the [tool call loop](#tool-call-loop) handles.

Treat function names and arguments as untrusted input. Only dispatch functions you have explicitly allowed, and validate arguments before executing them, as `$getWeather` does.

### Tool call loop

To let the model call tools until it has an answer, run a loop. Stream a turn, run each function call as soon as it finishes streaming, then send the outputs back along with the model's output. Stop when a turn makes no function calls, and cap the number of turns so a model that keeps calling tools cannot loop forever. This reuses `$getWeatherTool` and `$getWeather` from the example above:

```php
use TiborSrc\XaiSdkPhp\SpaceXAI;

$client = new SpaceXAI();
$tools = [$getWeatherTool];
$handlers = [
    'get_weather' => $getWeather,
];

$runTool = function (array $call) use ($handlers): array {
    try {
        $handler = $handlers[$call['name']] ?? null;
        if ($handler === null) {
            throw new RuntimeException('Unknown tool: ' . $call['name']);
        }
        $output = $handler(json_decode($call['arguments'], true));
    } catch (Throwable $exception) {
        $output = ['error' => $exception->getMessage()];
    }

    return [
        'type' => 'function_call_output',
        'call_id' => $call['call_id'],
        'output' => json_encode($output),
    ];
};

$input = [
    ['role' => 'user', 'content' => 'Compare the weather in Paris and Tokyo.'],
];

for ($turn = 0; $turn < 10; $turn++) {
    $toolOutputs = [];
    $stream = $client->responses->create([
        'model' => 'grok-4.7',
        'input' => $input,
        'tools' => $tools,
        'stream' => true,
    ]);
    $response = $stream
        ->on('text', function (string $text): void {
            echo $text;
        })
        ->on('client_tool_call', function (array $call) use (&$toolOutputs, $runTool): void {
            if (($call['type'] ?? null) === 'function_call') {
                $toolOutputs[] = $runTool($call);
            }
        })
        ->done();
    if ($toolOutputs === []) {
        break;
    }

    array_push($input, ...$response->toInput(), ...$toolOutputs);
}
```

The `$handlers` map is the list of functions the model may call. `$runTool` returns errors to the model, so the model can recover, and a failing tool stays inside the loop while the stream is still running.

### Shell commands

With the `shell` tool, the model writes shell commands and your application runs them. Use it for agents that work on your machine, such as exploring a repository, running tests, or checking disk space. Each call arrives through the `client_tool_call` listener as a `shell_call`, with the commands in `action.commands`. The model writes these commands, so run them in a sandbox or container, or check each one before running it:

```php
use TiborSrc\XaiSdkPhp\SpaceXAI;

$client = new SpaceXAI();

$stream = $client->responses->create([
    'model' => 'grok-4.7',
    'input' => 'How much free disk space does this machine have?',
    'tools' => [['type' => 'shell', 'environment' => ['type' => 'local']]],
    'stream' => true,
]);

$stream
    ->on('client_tool_call', function (array $call): void {
        if (($call['type'] ?? null) !== 'shell_call') {
            return;
        }
        foreach ($call['action']['commands'] as $command) {
            echo shell_exec($command);
        }
    })
    ->done();
```

To let the model use the results, send them back in the next request, like the function outputs in the [tool call loop](#tool-call-loop): `['type' => 'shell_call_output', 'call_id' => $call['call_id'], 'output' => [['stdout' => $stdout, 'stderr' => $stderr, 'outcome' => ['type' => 'exit', 'exit_code' => 0]]]]`.

To give the model skills, list them in the tool's `environment`. A skill is a directory with a `SKILL.md` file of instructions. The model sees each skill's name and description, and when a task matches one, it reads the `SKILL.md` through your shell tool and follows it. If you only allow certain commands, allow reading the skill's directory:

```php
$shell = [
    'type' => 'shell',
    'environment' => [
        'type' => 'local',
        'skills' => [
            [
                'name' => 'release-notes',
                'description' => "Write release notes from this repo's git log in our house style.",
                'path' => './skills/release-notes',
            ],
        ],
    ],
];
```

Asked to write release notes, the model reads `./skills/release-notes/SKILL.md`, runs the `git log` command it describes, and writes the notes in the format it specifies.

## Built-in tools

Besides your own functions, the API has built-in tools. Create them with the helpers in this package, which set each tool's `type`. SpaceXAI runs these tools and includes their results in the response:

- `webSearch()` (`web_search`) searches the web. Options include `allowed_domains`, `excluded_domains`, `user_location`, and `search_context_size`.
- `xSearch()` (`x_search`) searches posts on X. Options include `allowed_x_handles`, `excluded_x_handles`, `from_date`, and `to_date`.
- `codeExecution()` (`code_interpreter`) writes and runs Python code to answer the prompt.
- `collectionsSearch()` (`file_search`) searches the [collections](https://docs.x.ai/developers/files/collections) listed in `vector_store_ids`.
- `imageGeneration()` (`image_generation`) creates or edits images.
- `mcp()` (`mcp`) calls tools on the remote MCP server at `server_url`, identified by `server_label`.
- `toolSearch()` (`tool_search`) loads the definitions of tools marked `defer_loading` when the model needs them, instead of putting every definition in the prompt.

Two kinds of tools run in your application: `function` for your own functions, as shown in [Tools](#tools), and `shell`, where the model writes shell commands for your application to run, as shown in [Shell commands](#shell-commands). When you stream, calls to both arrive through the `client_tool_call` listener.

The helpers return plain tool arrays, so you can also write `['type' => 'web_search']` yourself. See the [SpaceXAI documentation](https://docs.x.ai) for each tool's options.

### Web search

Add the web search tool when a prompt needs current information:

```php
use TiborSrc\XaiSdkPhp\SpaceXAI;

use function TiborSrc\XaiSdkPhp\webSearch;

$client = new SpaceXAI();

$response = $client->responses->create([
    'model' => 'grok-4.7',
    'input' => 'What are the latest developments in commercial spaceflight?',
    'tools' => [webSearch()],
]);

echo $response->toText();
echo $response->usage->num_server_side_tools_used;
```

### X search

Search posts on X, optionally limited to certain accounts and dates:

```php
use function TiborSrc\XaiSdkPhp\xSearch;

$response = $client->responses->create([
    'model' => 'grok-4.7',
    'input' => 'What has SpaceXAI announced on X this month?',
    'tools' => [xSearch(['allowed_x_handles' => ['xai'], 'from_date' => '2026-09-01'])],
]);
```

`allowed_x_handles` and `excluded_x_handles` each take up to 20 handles. A search uses one of those lists. Set `enable_image_understanding` or `enable_video_understanding` to let the model look at media in posts.

`to_date` is exclusive, so a single day runs from that date to the next: `xSearch(['from_date' => '2026-10-01', 'to_date' => '2026-10-02'])`. Without dates, the model chooses which dates to search.

When you stream, each finished search reaches the `server_tool_call` listener as a `custom_tool_call`. Its `name` is the search that ran, such as `x_keyword_search`, and `input` holds the search arguments as a JSON string:

```php
$stream
    ->on('server_tool_call', function (array $call): void {
        if (($call['type'] ?? null) === 'custom_tool_call') {
            echo $call['name'], ' ', $call['input'], "\n";
        }
    })
    ->done();
```

### Code execution

Let the model write and run Python for calculations and data analysis:

```php
use function TiborSrc\XaiSdkPhp\codeExecution;

$response = $client->responses->create([
    'model' => 'grok-4.7',
    'input' => 'What is the standard deviation of 12, 15, 19, 22, and 31?',
    'tools' => [codeExecution()],
]);
```

The code runs in a sandbox with common libraries installed. The tool takes no options.

### Collections search

Search documents you have added to [collections](https://docs.x.ai/developers/files/collections):

```php
use function TiborSrc\XaiSdkPhp\collectionsSearch;

$response = $client->responses->create([
    'model' => 'grok-4.7',
    'input' => 'What does our refund policy say about digital purchases?',
    'tools' => [collectionsSearch(['vector_store_ids' => ['your-collection-id'], 'max_num_results' => 10])],
]);
```

### Remote MCP servers

Give the model the tools of a remote MCP server. SpaceXAI connects to the server and calls its tools during the response:

```php
use function TiborSrc\XaiSdkPhp\mcp;

$response = $client->responses->create([
    'model' => 'grok-4.7',
    'input' => 'What is the modelcontextprotocol/typescript-sdk repository for?',
    'tools' => [mcp(['server_url' => 'https://mcp.deepwiki.com/mcp', 'server_label' => 'deepwiki'])],
]);
```

The server must use the Streaming HTTP or SSE transport. Limit the model to some of the server's tools with `allowed_tools`, and pass credentials with `authorization` or `headers`. The TypeScript SDK documents `require_approval` and `connector_id` as unsupported for now. For a server with many tools, set `defer_loading` to `true` on it and add `toolSearch()`, so the model loads only the tool definitions it needs.

### Image generation tool

Add the image generation tool to let the model create or edit images as one step of a response. Each image arrives as an `image_generation_call` output item whose `result` holds base64 image data:

```php
use TiborSrc\XaiSdkPhp\SpaceXAI;

use function TiborSrc\XaiSdkPhp\imageGeneration;
use function TiborSrc\XaiSdkPhp\isImageGenerationCall;

$client = new SpaceXAI();

$response = $client->responses->create([
    'model' => 'grok-4.7',
    'input' => 'Generate an image of a corgi surfing a big wave, in the style of a Japanese woodblock print.',
    'tools' => [imageGeneration()],
]);

echo $response->toText();

foreach ($response->output as $item) {
    if (isImageGenerationCall($item) && is_string($item['result'] ?? null)) {
        file_put_contents('corgi.jpg', base64_decode($item['result']));
    }
}
```

Pass `action` as `generate` or `edit` to `imageGeneration()` to allow only one of those capabilities. When streaming, each call emits `response.image_generation_call.in_progress`, `response.image_generation_call.generating`, and `response.image_generation_call.completed` events, then a `response.output_item.done` event carries the finished item.

To generate or edit an image directly with full control over its size and format, use the [image generation](#image-generation) and [image editing](#image-editing) methods.

## Working with responses

Every completed response provides:

- `$response->toText()` to concatenate output text
- `$response->toInput()` to carry all output items into a later request
- `$response->toJson()` to parse completed JSON output
- `$response->parsed` for non-throwing JSON parsing
- `$response->output` for output items
- `$response->usage` for token counts, server-side tool use, and cost when available
- `$response->http` for the HTTP status, headers, SpaceXAI request ID, and client request ID
- `$response->raw` for the response object as the API sent it, including fields this SDK does not model

Use the exported functions when inspecting output items:

```php
use function TiborSrc\XaiSdkPhp\isFunctionCall;
use function TiborSrc\XaiSdkPhp\isMessage;
use function TiborSrc\XaiSdkPhp\isReasoning;

foreach ($response->output as $item) {
    if (isMessage($item)) {
        var_export($item['content']);
    } elseif (isFunctionCall($item)) {
        echo $item['name'], ' ', $item['arguments'], "\n";
    } elseif (isReasoning($item)) {
        echo 'reasoning item ', $item['id'], "\n";
    }
}
```

## Image generation

Generate images from a text prompt with a Grok Imagine model. Images are returned as temporary URLs by default, so download or process them promptly:

```php
$result = $client->images->generate([
    'model' => 'grok-imagine-image-2.0',
    'prompt' => 'A collage of London landmarks in a stenciled street-art style',
]);

echo $result->data[0]['url'] ?? '';
echo $result->usage?->cost_usd;
```

Request up to 10 images with `n`, and shape the output with `aspect_ratio`, `resolution`, and `quality`. Only `grok-imagine-image-2.0` supports `quality`. Set `response_format` to `b64_json` to receive base64 data:

```php
$result = $client->images->generate([
    'model' => 'grok-imagine-image-2.0',
    'prompt' => 'A futuristic city skyline at night',
    'n' => 4,
    'aspect_ratio' => '16:9',
    'resolution' => '2k',
    'response_format' => 'b64_json',
]);

foreach ($result->data as $index => $image) {
    if (is_string($image['b64_json'] ?? null)) {
        file_put_contents("skyline-{$index}.jpg", base64_decode($image['b64_json']));
    }
}
```

Base64 output is about a third larger than the image file, so large batches of high-resolution images can approach the default 32 MiB response size limit. Raise `maxResponseBodyBytes` for those requests:

```php
$result = $client->images->generate(
    [
        'model' => 'grok-imagine-image-2.0',
        'prompt' => 'A futuristic city skyline at night',
        'n' => 10,
        'resolution' => '2k',
        'response_format' => 'b64_json',
    ],
    ['maxResponseBodyBytes' => 128 * 1024 * 1024],
);
```

Each result provides `data`, `usage`, and `http`. `usage->cost_usd` converts the reported `cost_in_usd_ticks` to US dollars, and `usage` is `null` when the API omits it.

## Image editing

Pass a source image with your prompt to edit it. `image` accepts a public URL, a base64 data URL, a Files API `file_id`, or a `Blob` or `File`, which the SDK converts to a data URL before sending the request:

```php
use TiborSrc\XaiSdkPhp\Http\Blob;

$photo = new Blob(file_get_contents('./photo.png'), 'image/png');

$result = $client->images->edit([
    'model' => 'grok-imagine-image-2.0',
    'prompt' => 'Render this as a pencil sketch with detailed shading',
    'image' => $photo,
]);

echo $result->data[0]['url'] ?? '';
```

When a `Blob` or `File` has an empty MIME type, the SDK detects JPEG, PNG, or WebP from its first bytes. The API rejects image data URLs that are not typed as one of those formats.

To combine up to five source images, pass `images` and refer to them in the prompt as `<IMAGE_0>`, `<IMAGE_1>`, and so on. The output follows the first image's aspect ratio unless you set `aspect_ratio`:

```php
$result = $client->images->edit([
    'model' => 'grok-imagine-image-2.0',
    'prompt' => 'Place the cat from <IMAGE_0> on the sofa from <IMAGE_1>',
    'images' => [
        ['url' => 'https://example.com/cat.png'],
        ['file_id' => 'file_abc123'],
    ],
    'aspect_ratio' => '16:9',
]);
```

## Video generation

Video generation runs as a background job. `generate()` starts the job and returns its `request_id`, and `wait()` polls until the job finishes:

```php
$started = $client->videos->generate([
    'model' => 'grok-imagine-video-1.5',
    'prompt' => 'A paper boat drifting down a rain-soaked street',
    'duration' => 8,
    'aspect_ratio' => '16:9',
    'resolution' => '720p',
]);

$result = $client->videos->wait($started->request_id);
if ($result->status === 'done') {
    echo $result->video['url'] ?? '';
    echo $result->usage?->cost_usd;
} else {
    fwrite(STDERR, $result->status . ' ' . ($result->error['code'] ?? '') . ' ' . ($result->error['message'] ?? ''));
}
```

`wait()` returns once the status is `done`, `failed`, or `expired`. A failed result includes an `error` with a `code` and `message`. If `video.respect_moderation` is `false`, the video did not pass moderation and has no URL. Video URLs are temporary, so download the file promptly.

`wait()` polls every 5 seconds for up to 10 minutes. Pass `interval` and `timeout` in milliseconds to change this, and a `signal` to stop waiting. A timeout throws `TimeoutError`, so you can call `wait()` again. A timeout or an aborted signal leaves the job running: the video keeps generating and is billed when it finishes. The API has no way to cancel a video in this SDK version. To check once, call `$client->videos->get($requestId)`, which returns `status` `pending` until the video is ready.

To animate a still image, pass it as `image`. `image`, `reference_images`, and keyframe images accept a public URL, a base64 data URL, a Files API `file_id`, or a `Blob` or `File`, which the SDK converts to a data URL before sending the request:

```php
use TiborSrc\XaiSdkPhp\Http\Blob;

$started = $client->videos->generate([
    'model' => 'grok-imagine-video-1.5',
    'prompt' => 'Make the water crash down and slowly pan out the camera',
    'image' => new Blob(file_get_contents('./waterfall.png'), 'image/png'),
]);
```

Edit a video with `edit()`, or continue it from its last frame with `extend()`. Both return a `request_id` for `wait()`. The source `video` must be an MP4, given as a public URL, a base64 data URL, a Files API `file_id`, or a `Blob` or `File`, which the SDK converts to a data URL before sending the request. For extensions, `duration` sets the length of the new segment only:

```php
use TiborSrc\XaiSdkPhp\Http\Blob;

$edit = $client->videos->edit([
    'model' => 'grok-imagine-video',
    'prompt' => 'Give the woman a silver necklace',
    'video' => new Blob(file_get_contents('portrait.mp4'), 'video/mp4'),
]);

$extension = $client->videos->extend([
    'model' => 'grok-imagine-video',
    'prompt' => 'The camera slowly zooms out to reveal the city skyline',
    'video' => ['file_id' => 'file_abc123'],
    'duration' => 6,
]);
```

A `Blob` or `File` with an empty MIME type is sent as `video/mp4`. Because the video travels inside the request as base64, which is a third larger than the file, upload large videos with `$client->files->upload()` (up to 50 MB) and pass `['file_id' => ...]` .

List the video generation models available to your API key with `$client->models->video->list()`, or look one up by ID with `$client->models->video->get()`.

## Files

Upload a document, image, or video once and refer to it by ID. A file ID works wherever the API accepts a `file_id`, such as an `input_file` part in the Responses API or an image or video input:

```php
use TiborSrc\XaiSdkPhp\Http\File;

$file = $client->files->upload([
    'file' => new File(file_get_contents('./report.pdf'), 'report.pdf', 'application/pdf'),
    'filename' => 'report.pdf',
]);

$response = $client->responses->create([
    'model' => 'grok-4.7',
    'input' => [
        [
            'role' => 'user',
            'content' => [
                ['type' => 'input_text', 'text' => 'Summarize the key findings in this report.'],
                ['type' => 'input_file', 'file_id' => $file->id],
            ],
        ],
    ],
]);

echo $response->toText();
```

The API records the upload's filename as the file's `filename`. A `File` uses its own name, and a plain `Blob` needs `filename`. Files are kept until you delete them. Set `expires_after` to between 3,600 and 2,592,000 seconds (1 hour to 30 days) to have one deleted automatically.

List, download, share, and delete stored files:

```php
foreach ($client->files->list() as $stored) {
    echo $stored['id'], ' ', $stored['filename'], ' ', $stored['bytes'], "\n";
}

$content = $client->files->content($file->id);
file_put_contents('report-copy.pdf', $content->bytes());

$shared = $client->files->createPublicUrl($file->id, [
    'expires_after' => 86_400,
]);
echo $shared->public_url;

$client->files->revokePublicUrl($file->id);
$client->files->delete($file->id);
```

`list()` returns the newest files first and fetches further pages as the loop needs them. `content()` returns a `BinaryResponse`: read it with `bytes()`, `text()`, or `blob()`, or read `$content->body` as a stream.

Anyone with a public URL can download the file without an API key. Only images, videos, and PDFs up to 50 MiB can be made public. A file has at most one public URL, so calling `createPublicUrl()` again returns the existing URL and updates its expiry if you pass a new `expires_after`. Without `expires_after`, the URL lasts as long as the file unless you revoke it. After revoking, copies already cached by the CDN can still be served briefly.

## Batch API

The Batch API processes large volumes of requests asynchronously at a reduced price. Most requests complete within 24 hours. Create a batch, then add requests to it:

```php
$batch = $client->batches->create(['name' => 'feedback_sentiment']);

$feedback = [
    ['id' => 'feedback_001', 'text' => 'The product exceeded my expectations!'],
    ['id' => 'feedback_002', 'text' => 'Shipping took way too long.'],
];

$client->batches->requests->add($batch->batch_id, [
    'batch_requests' => array_map(fn (array $item): array => [
        'batch_request_id' => $item['id'],
        'batch_request' => [
            'responses' => [
                'model' => 'grok-4.7',
                'input' => [
                    ['role' => 'system', 'content' => 'Classify the sentiment as positive, negative, or neutral.'],
                    ['role' => 'user', 'content' => $item['text']],
                ],
            ],
        ],
    ], $feedback),
]);
```

Each `batch_request` holds one request. `responses` takes the same body as `$client->responses->create()`, including the `store` default of `false`, and its result comes back as a `chat_get_completion` response. `image_generation`, `image_edit`, `video_generation`, and `video_extension` take the request body of the matching REST endpoint. Results can come back in any order, so give each request a `batch_request_id` that is unique within the batch. Each [model page](https://docs.x.ai/developers/models) lists its Batch API support.

Wait until no requests are pending, then read the results:

```php
$client->batches->wait($batch->batch_id);

foreach ($client->batches->results($batch->batch_id) as $row) {
    $batchResult = $row['batch_result'];
    if (isset($batchResult['error'])) {
        fwrite(STDERR, $row['batch_request_id'] . ' ' . json_encode($batchResult['error']) . "\n");
    } else {
        echo $row['batch_request_id'], ' ', json_encode($batchResult['response']), "\n";
    }
}
```

`wait()` polls every 5 seconds and throws `TimeoutError` after 24 hours. Pass `interval`, `timeout`, or `signal` to change that. Results are available as soon as each request finishes, so you can read them before the whole batch completes. Use `$client->batches->requests->list()` to check the state of individual requests, `$client->batches->list()` to list your team's batches, and `$client->batches->cancel()` to stop the remaining requests. Finished results stay available after cancelling.

## Voice

Convert text to speech with `$client->voice->speak()`. The audio comes back as a `BinaryResponse`, encoded as MP3 unless you set `output_format`:

```php
$speech = $client->voice->speak([
    'text' => 'Welcome to SpaceX. [pause] How can I help you today?',
    'language' => 'en',
    'voice_id' => 'eve',
]);

file_put_contents('welcome.mp3', $speech->bytes());
```

Shape the delivery with [speech tags](https://docs.x.ai/developers/model-capabilities/audio/text-to-speech#speech-tags) in the text. Inline tags such as `[pause]`, `[long-pause]`, and `[laugh]` go where the sound should happen, and wrapping tags such as `<whisper>It's a secret.</whisper>` change how the enclosed text is spoken. The API does not report mistakes in tags. `checkSpeechText()` reports unknown tags, suggests the closest known tag, and reports wrapping tags that are never closed, closed without being opened, or closed in the wrong order. `stripInvalidSpeechTags()` removes any tag the API would not recognize and keeps the words it wraps.

Text you do not write yourself, such as a script the model wrote or text your users submit, can contain a made-up tag such as `[laff]`, and the API reads it aloud. Put the real tags in the prompt with `INLINE_SPEECH_TAGS` and `WRAPPING_SPEECH_TAGS`, then call `stripInvalidSpeechTags()` before speaking. `checkSpeechText()` returns the problems for logging, for showing to a user, or for asking the model to fix its text:

```php
use TiborSrc\XaiSdkPhp\INLINE_SPEECH_TAGS;

use function TiborSrc\XaiSdkPhp\checkSpeechText;
use function TiborSrc\XaiSdkPhp\stripInvalidSpeechTags;

$tags = implode(', ', array_map(fn (string $tag): string => "[{$tag}]", INLINE_SPEECH_TAGS));
$response = $client->responses->create([
    'model' => 'grok-4.7',
    'input' => "Write the opening line of a podcast about volcanoes. You can use these speech tags: {$tags}.",
]);
$line = $response->toText();

$problems = checkSpeechText($line); // For example: ['Unknown speech tag [laff], did you mean [laugh]?']
if ($problems !== []) {
    fwrite(STDERR, implode("\n", $problems));
}

$client->voice->speak(['text' => stripInvalidSpeechTags($line), 'language' => 'en']);
```

`voice_id` accepts the built-in voices in `KNOWN_VOICE_IDS` and any other string, such as a custom voice ID or a voice added after this SDK version. List the built-in voices with `$client->voice->list()`. To start playback before synthesis finishes, read `$speech->body` as a stream. Set `with_timestamps` to `true` to receive JSON with base64 `audio` and per-character `audio_timestamps`.

Transcribe a recording with `$client->voice->transcribe()`. Pass the audio as a `Blob` or `File`, or pass `url` to have the API download it:

```php
use TiborSrc\XaiSdkPhp\Http\Blob;

$transcript = $client->voice->transcribe([
    'file' => new Blob(file_get_contents('./meeting.mp3'), 'audio/mpeg'),
    'language' => 'en',
    'format' => true,
]);

echo $transcript->text;
```

`format` set to `true` writes spoken numbers, currencies, and units in written form, and requires `language`. Word-level timings are in `$transcript->words`.

Clone a voice from a reference clip of up to 120 seconds with `$client->voice->custom->create()`. Creating custom voices through the API requires an Enterprise plan:

```php
use TiborSrc\XaiSdkPhp\Http\Blob;

$voice = $client->voice->custom->create([
    'file' => new Blob(file_get_contents('./reference.wav'), 'audio/wav'),
    'name' => 'Friendly Narrator',
    'language' => 'en',
]);

echo $voice->voice_id;
```

Pass the returned `voice_id` to `speak()` or a realtime session like a built-in voice. `$client->voice->custom` also provides `list()`, `get()`, `update()`, `delete()`, and `getAudio()`, which downloads the reference clip.

Realtime voice sessions in a browser should authenticate with a short-lived client secret. Create one on your server:

```php
$secret = $client->voice->clientSecrets->create([
    'expires_after' => ['seconds' => 300],
]);
```

Send `$secret->value` to the browser, which passes `xai-client-secret.<value>` as the WebSocket subprotocol when it connects to `wss://api.x.ai/v1/realtime`. Secrets expire after 10 minutes by default, and `expires_after.seconds` can be at most 3600.

## Tokenization

Encode text with a language model's tokenizer to count its tokens or see how it is split:

```php
$encoded = $client->tokenizer->encode([
    'model' => 'grok-4.7',
    'text' => 'Hello world!',
]);

echo count($encoded->token_ids), "\n";
foreach ($encoded->token_ids as $token) {
    echo $token['token_id'], ' ', $token['string_token'], "\n";
}
```

Inference requests add tokens of their own, so `usage->input_tokens` for a prompt can be higher than this count.

The API has no decode endpoint, but each token carries its bytes, so you can turn encoded tokens back into text. Decode `token_bytes` rather than joining `string_token`, because a token can hold part of a multi-byte character:

```php
$bytes = '';
foreach ($encoded->token_ids as $token) {
    foreach ($token['token_bytes'] as $byte) {
        $bytes .= chr($byte);
    }
}
$text = $bytes;
```

## Models

Use model IDs directly. `KNOWN_MODEL_IDS` lists the IDs known to this release. Any other string is accepted, including a model released after this version:

```php
use TiborSrc\XaiSdkPhp\KNOWN_MODEL_IDS;

$model = 'grok-4.7';

$available = $client->models->list();
foreach ($available->data as $availableModel) {
    echo $availableModel['id'], "\n";
}

$modelInfo = $client->models->get($model);
```

`KNOWN_MODEL_IDS` is taken from the models known to the TypeScript SDK this port follows. See the [SpaceXAI model documentation](https://docs.x.ai/developers/models) for the current catalog.

Image generation models have their own catalog, which includes modalities, aliases, and pricing. `KNOWN_IMAGE_MODEL_IDS` lists the image model IDs known to this release:

```php
$imageModels = $client->models->image->list();
foreach ($imageModels->models as $availableImageModel) {
    echo $availableImageModel['id'], ' ', json_encode($availableImageModel['aliases']), "\n";
}

$imageModelInfo = $client->models->image->get('grok-imagine-image-2.0');
```

Chat and image understanding models have their own catalog, which includes modalities, aliases, token pricing, and supported reasoning efforts:

```php
$languageModels = $client->models->language->list();
foreach ($languageModels->models as $languageModel) {
    echo $languageModel['id'], ' ', json_encode($languageModel['input_modalities']), "\n";
}

$languageModelInfo = $client->models->language->get('grok-4.7');
var_export($languageModelInfo->capabilities['reasoning_effort'] ?? null);
```

Token prices such as `prompt_text_token_price` are in USD cents per 100 million tokens. Divide them by 10,000 for US dollars per million tokens.

## Account

`$client->account->apiKey()` returns the name, status, and permissions of the API key the client is using:

```php
$apiKeyInfo = $client->account->apiKey();
echo $apiKeyInfo->name, ' ', json_encode($apiKeyInfo->acls), ' ', json_encode($apiKeyInfo->api_key_disabled);
```

## Response storage

The SDK sends `store` as `false` unless you opt in. This differs from the API wire default. With storage disabled, the SDK requests encrypted reasoning content so `$response->toInput()` can preserve context between turns.

Pass `store` as `true` when you need to retrieve, continue, inspect, or delete a response by ID:

```php
$stored = $client->responses->create([
    'model' => 'grok-4.7',
    'input' => 'Save this response.',
    'store' => true,
]);

$fetched = $client->responses->get($stored->id);
$inputItems = $client->responses->inputItems->list($stored->id);
$client->responses->delete($stored->id);
```

## Pagination

List methods that return results in pages fetch the next page as you iterate:

```php
foreach ($client->files->list() as $file) {
    echo $file['id'], "\n";
}
```

This works for `files->list()`, `batches->list()`, `batches->results()`, `batches->requests->list()`, `voice->custom->list()`, and `responses->inputItems->list()`. Calling one of these methods returns a single page. Iterate that page to follow further pages.

## Timeouts, retries, and cancellation

Configure defaults on the client:

```php
$client = new SpaceXAI([
    'timeout' => 60_000,
    'idleTimeout' => 30_000,
    'maxRetries' => 2,
]);
```

Override them for one request, and pass an `AbortSignal` when you need to stop it:

```php
use TiborSrc\XaiSdkPhp\Http\AbortController;

$controller = new AbortController();

$response = $client->responses->create(
    [
        'model' => 'grok-4.7',
        'input' => 'Write a detailed report.',
    ],
    [
        'signal' => $controller->signal,
        'timeout' => 120_000,
        'maxRetries' => 0,
    ],
);
```

`$controller->abort()` stops a request the next time the client checks the signal, which happens while the HTTP transfer is in progress. `timeout` is the overall deadline in milliseconds. The default timeout is 3,600,000 (one hour). The default idle timeout is 60,000.

Requests that generate content, such as `responses->create`, `images->generate`, and `images->edit`, retry only explicit `429` responses by default. Read-only requests may also retry transient HTTP failures. Retry delays honor `Retry-After` and otherwise use jittered exponential backoff, which starts at 1 second for a `429`.

Set `retryBeforeOutput` to `true`, on the client or on one request, to also retry streamed `responses->create()` calls, including ones that omit `stream`, when they fail before the model produces any output: a `5xx` status, a dropped connection, or a stream error such as a `503` right after `response.created`. All retries of a call share `maxRetries`, so a call makes at most `maxRetries + 1` requests. Events from the failed attempt do not reach your listeners or loop, and every attempt sends the same `x-client-request-id`. Each retry starts a new response, and the API may still bill the failed attempt's input tokens, so this is off by default. Errors after output has started stay as they are, and so do `5xx` responses to requests with `stream` set to `false`, which can arrive after the model has finished.

When you leave out `stream`, `responses->create()` streams the response under the hood and returns the final response. Those requests apply `idleTimeout` only when you pass it on the request. Set `stream` to `false` to send a plain JSON request. An explicit stream uses the client idle timeout.

## Errors

All SDK errors extend `APIError`. Status-specific classes cover common API failures.

```php
use TiborSrc\XaiSdkPhp\APIError;
use TiborSrc\XaiSdkPhp\AuthenticationError;
use TiborSrc\XaiSdkPhp\RateLimitError;

try {
    $client->responses->create([
        'model' => 'grok-4.7',
        'input' => 'Hello',
    ]);
} catch (AuthenticationError) {
    fwrite(STDERR, "Check XAI_API_KEY\n");
} catch (RateLimitError) {
    fwrite(STDERR, "Rate limited. Retry later.\n");
} catch (APIError $error) {
    fwrite(STDERR, "{$error->status} {$error->code} {$error->param} {$error->requestId} {$error->getMessage()}\n");
}
```

The SDK includes `APIConnectionError`, `APIProtocolError`, `APIStatusError`, `AbortError`, `AuthenticationError`, `NotFoundError`, `OverloadedError`, `PermissionDeniedError`, `RateLimitError`, and `TimeoutError`. `APIError::is()` reports whether a value is one of these errors.

## Debugging requests

Set `XAI_DEBUG=1` to print each request's method, URL, and headers as a cURL command on standard error:

```bash
XAI_DEBUG=1 php app.php
```

Authentication headers and common credential fields are redacted. Request bodies are omitted because prompts and tool outputs may contain sensitive data.

Structured API failures expose `$error->type`, `$error->code`, and `$error->param` when the server returns them. The SpaceXAI request ID is also available at `$response->http->requestId` and `$error->requestId`. Include it when reporting an API problem.

Every request also sends an `x-client-request-id` header with a UUID generated by the SDK. The ID stays the same across retries and is available at `$response->http->clientRequestId` and `$error->clientRequestId`, even when a request fails before the API responds. To use your own ID, set `x-client-request-id` in the request `headers`.

## Development

```bash
composer install
composer test
```

`composer test` runs the Pest suite. `tests/E2eTest.php` calls the live API and is skipped unless `XAI_API_KEY` is set.

## License

Licensed under the [Apache License 2.0](./LICENSE). See [NOTICE](./NOTICE). This repository is an independent port of the SpaceXAI TypeScript SDK and is not an official xAI package.
