<?php

declare(strict_types=1);

use TiborSrc\XaiSdkPhp\APIConnectionError;
use TiborSrc\XaiSdkPhp\APIProtocolError;
use TiborSrc\XaiSdkPhp\AuthenticationError;
use TiborSrc\XaiSdkPhp\Http\Blob;
use TiborSrc\XaiSdkPhp\Http\File;
use TiborSrc\XaiSdkPhp\Http\FormData;
use TiborSrc\XaiSdkPhp\Http\HeaderBag;
use TiborSrc\XaiSdkPhp\RateLimitError;
use TiborSrc\XaiSdkPhp\SpaceXAI;
use TiborSrc\XaiSdkPhp\TimeoutError;
use TiborSrc\XaiSdkPhp\Transport;

use function TiborSrc\XaiSdkPhp\codeExecution;
use function TiborSrc\XaiSdkPhp\webSearch;

it('requires an api key', function () {
    $previous = getenv('XAI_API_KEY');
    putenv('XAI_API_KEY');
    unset($_ENV['XAI_API_KEY'], $_SERVER['XAI_API_KEY']);

    try {
        expect(fn () => new SpaceXAI())->toThrow(RuntimeException::class, 'SpaceXAI: apiKey is missing (set XAI_API_KEY or pass apiKey)');
    } finally {
        if (is_string($previous) && $previous !== '') {
            putenv('XAI_API_KEY=' . $previous);
        }
    }
});

it('omits the api key from debug output', function () {
    $client = new SpaceXAI(['apiKey' => 'secret-key', 'fetch' => fn () => jsonResponse([])]);

    expect(print_r($client, true))->not->toContain('secret-key');
    expect($client->baseURL)->toBe('https://api.x.ai/v1');
});

it('defaults store to false and streams a create that omits stream', function () {
    [$client, $captured] = mockClient(fn () => jsonResponse(completedResponse()));
    $response = $client->responses->create([
        'model' => 'grok-4.6',
        'input' => 'hi',
    ]);

    expect($response->toText())->toBe('Hello world');
    expect($captured->requests[0]->json()['store'])->toBeFalse();
    expect($captured->requests[0]->json()['include'])->toBe(['reasoning.encrypted_content']);
    expect($captured->requests[0]->json()['stream'])->toBeTrue();
    expect($captured->requests[0]->headers->get('accept'))->toBe('text/event-stream');
    expect($captured->requests[0]->headers->get('user-agent'))->toBe('xai-sdk/0.2.1 (php)');
    expect($captured->requests[0]->headers->get('xai-sdk-version'))->toBe('php/0.2.1');
    expect($captured->requests[0]->headers->get('authorization'))->toBe('Bearer k');
});

it('sends stream false as json', function () {
    [$client, $captured] = mockClient(fn () => jsonResponse(completedResponse()));
    $client->responses->create([
        'model' => 'grok-4.6',
        'input' => 'hi',
        'stream' => false,
    ]);

    expect($captured->requests[0]->json()['stream'])->toBeFalse();
    expect($captured->requests[0]->headers->get('accept'))->toBe('application/json');
});

it('does not add include when store is true', function () {
    [$client, $captured] = mockClient(fn () => jsonResponse(completedResponse()));
    $client->responses->create([
        'model' => 'grok-4.6',
        'input' => 'hi',
        'store' => true,
        'stream' => false,
    ]);

    expect($captured->requests[0]->json()['store'])->toBeTrue();
    expect($captured->requests[0]->json())->not->toHaveKey('include');
});

it('merges encrypted reasoning into include', function () {
    [$client, $captured] = mockClient(fn () => jsonResponse(completedResponse()));
    $client->responses->create([
        'model' => 'grok-4.6',
        'input' => 'hi',
        'store' => false,
        'include' => ['file_search_call.results'],
        'stream' => false,
    ]);

    expect($captured->requests[0]->json()['include'])->toBe([
        'file_search_call.results',
        'reasoning.encrypted_content',
    ]);
});

it('keeps a caller user agent and overwrites sdk attribution', function () {
    [$client, $captured] = mockClient(fn () => jsonResponse(completedResponse()), [
        'defaultHeaders' => [
            'user-agent' => 'curl/8',
            'xai-sdk-version' => 'python/9.9.9',
            'xai-sdk-language' => 'python/3.12',
        ],
    ]);
    $client->responses->create([
        'model' => 'grok-4.6',
        'input' => 'hi',
        'stream' => false,
    ]);

    expect($captured->requests[0]->headers->get('user-agent'))->toBe('curl/8');
    expect($captured->requests[0]->headers->get('xai-sdk-version'))->toBe('php/0.2.1');
    expect($captured->requests[0]->headers->get('xai-sdk-language'))->toBe('php/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION);
});

it('maps usage and keeps unrelated fields', function () {
    [$client] = mockClient(fn () => jsonResponse(completedResponse()));
    $response = $client->responses->create([
        'model' => 'grok-4.6',
        'input' => 'hi',
        'stream' => false,
    ]);

    expect($response->usage->cost_usd)->toBe(1.5);
    expect($response->usage['input_tokens_details'])->toBe(['cached_tokens' => 8]);
    expect($response->usage['context_details'])->toBe(['cached' => true]);
    expect($response->parsed)->toBeNull();
    expect($response->object)->toBe('response');
});

it('parses completed json output', function () {
    $body = completedResponse([
        'output' => [[
            'type' => 'message',
            'id' => 'msg_json',
            'role' => 'assistant',
            'status' => 'completed',
            'content' => [['type' => 'output_text', 'text' => '{"ok":true}']],
        ]],
    ]);
    [$client] = mockClient(fn () => jsonResponse($body));
    $response = $client->responses->create([
        'model' => 'grok-4.6',
        'input' => 'hi',
        'stream' => false,
    ]);

    expect($response->toJson())->toBe(['ok' => true]);
    expect($response->toJson([
        '~standard' => [
            'validate' => fn (mixed $value) => ['value' => $value],
        ],
    ]))->toBe(['ok' => true]);
});

it('rewrites a dropped reasoning error', function () {
    [$client] = mockClient(fn () => jsonResponse([
        'error' => ['message' => 'encrypted reasoning content is required', 'type' => 'invalid_request_error'],
    ], 400));

    expect(fn () => $client->responses->create([
        'model' => 'grok-4.6',
        'input' => 'hi',
        'stream' => false,
    ]))->toThrow(TiborSrc\XaiSdkPhp\APIStatusError::class, 'pass response.toInput() (or include encrypted reasoning)');
});

it('raises authentication errors for 401', function () {
    [$client] = mockClient(fn () => jsonResponse(['error' => ['message' => 'bad key']], 401));

    expect(fn () => $client->models->list())->toThrow(AuthenticationError::class);
});

it('retries a connection failure on get and reuses the client request id', function () {
    [$client, $captured] = mockClient(function ($request, int $n) {
        if ($n === 1) {
            throw new RuntimeException('fetch failed');
        }

        return jsonResponse([
            'object' => 'list',
            'data' => [['id' => 'grok-4.6', 'object' => 'model', 'created' => 1, 'owned_by' => 'xai']],
        ]);
    }, ['maxRetries' => 1]);

    $page = $client->models->list();

    expect($page['data'][0]['id'])->toBe('grok-4.6');
    expect($captured->requests)->toHaveCount(2);
    expect($captured->requests[0]->headers->get('x-client-request-id'))
        ->toBe($captured->requests[1]->headers->get('x-client-request-id'));
});

it('does not retry a post connection failure', function () {
    [$client, $captured] = mockClient(function () {
        throw new RuntimeException('fetch failed');
    }, ['maxRetries' => 2]);

    expect(fn () => $client->responses->create([
        'model' => 'grok-4.6',
        'input' => 'hi',
        'stream' => false,
    ]))->toThrow(APIConnectionError::class, 'fetch failed');
    expect($captured->requests)->toHaveCount(1);
});

it('retries 429 and honors retry-after 0', function () {
    [$client, $captured] = mockClient(function ($request, int $n) {
        if ($n === 1) {
            return jsonResponse(['error' => ['message' => 'slow down']], 429, ['retry-after' => '0']);
        }

        return jsonResponse(completedResponse());
    }, ['maxRetries' => 1]);

    $response = $client->responses->create([
        'model' => 'grok-4.6',
        'input' => 'hi',
        'stream' => false,
    ]);

    expect($response->id)->toBe('resp_123');
    expect($captured->requests)->toHaveCount(2);
});

it('does not follow redirects', function () {
    [$client, $captured] = mockClient(function () {
        throw new RuntimeException('redirect');
    }, ['maxRetries' => 2]);

    expect(fn () => $client->models->list())->toThrow(APIConnectionError::class, 'redirect');
    expect($captured->requests)->toHaveCount(1);
});

it('retries only the documented statuses', function () {
    expect(Transport::shouldRetryStatus('POST', 429))->toBeTrue();
    expect(Transport::shouldRetryStatus('POST', 500))->toBeFalse();
    expect(Transport::shouldRetryStatus('POST', 500, true))->toBeTrue();
    expect(Transport::shouldRetryStatus('GET', 408))->toBeTrue();
    expect(Transport::shouldRetryStatus('POST', 408))->toBeFalse();
    expect(Transport::retryDelayMs(0, '0'))->toBe(0.0);
    expect(Transport::retryDelayMs(0, '1.5'))->toBe(1500.0);
    expect(Transport::retryDelayMs(0, '2', 429))->toBe(2000.0);
    $delay = Transport::retryDelayMs(0, null, 429);
    expect($delay)->toBeGreaterThanOrEqual(500);
    expect($delay)->toBeLessThanOrEqual(1000);
});

it('redacts secrets in debug curls', function () {
    $headers = HeaderBag::from([
        'authorization' => 'Bearer secret-key',
        'cookie' => 'sid=abc',
        'x-demo-secret' => 'demo',
        'x-auth-token' => 'token',
        'x-api-key' => 'other',
        'accept' => 'application/json',
    ]);
    $curl = Transport::formatCurl(
        'POST',
        'https://fixture-user:fixture-password@api.x.ai/v1/responses?api_key=leak',
        $headers,
        '{"prompt":"sensitive-prompt"}',
    );

    expect($curl)->toContain('Bearer [REDACTED]');
    expect($curl)->toContain('cookie: [REDACTED]');
    expect($curl)->toContain('x-demo-secret: [REDACTED]');
    expect($curl)->toContain('x-api-key: [REDACTED]');
    expect($curl)->not->toContain('secret-key');
    expect($curl)->not->toContain('sid=abc');
    expect($curl)->toContain('Request body omitted');
    expect($curl)->not->toContain('sensitive-prompt');
    expect(Transport::formatCurl('POST', 'https://api.x.ai/v1/files', $headers, new FormData()))->toContain('Request body omitted');
});

it('streams text deltas and finishes on the terminal event', function () {
    $events = [
        ['type' => 'response.output_text.delta', 'delta' => 'Hello'],
        ['type' => 'response.output_text.delta', 'delta' => ' world'],
        ['type' => 'response.completed', 'response' => completedResponse()],
    ];
    [$client] = mockClient(fn () => sseResponse($events));
    $seen = '';
    $stream = $client->responses->create([
        'model' => 'grok-4.6',
        'input' => 'hi',
        'stream' => true,
    ]);
    $stream->on('text', function (string $delta) use (&$seen): void {
        $seen .= $delta;
    });

    expect($stream->done()->id)->toBe('resp_123');
    expect($seen)->toBe('Hello world');
    expect(fn () => $stream->on('not-an-event', fn () => null))->toThrow(TypeError::class);
});

it('synthesizes a deleted response for 204', function () {
    [$client] = mockClient(fn () => jsonResponse(null, 204));
    $deleted = $client->responses->delete('resp_123');

    expect($deleted->toArray())->toMatchArray([
        'id' => 'resp_123',
        'object' => 'response',
        'deleted' => true,
    ]);
});

it('rejects a model list that is not a list', function () {
    [$client] = mockClient(fn () => jsonResponse(['object' => 'list', 'data' => ['nope']]));

    expect(fn () => $client->models->list())->toThrow(APIProtocolError::class, 'Model list is missing object=list or data');
});

it('uploads file fields before the file part', function () {
    [$client, $captured] = mockClient(fn () => jsonResponse(['id' => 'file_1', 'filename' => 'notes.txt']));
    $file = $client->files->upload([
        'expires_after' => 3600,
        'purpose' => 'assistants',
        'file' => new File('hello', 'notes.txt', 'text/plain'),
    ]);

    expect($file->id)->toBe('file_1');
    $body = $captured->requests[0]->body;
    expect($body)->toBeInstanceOf(FormData::class);
    expect($body->keys())->toBe(['expires_after', 'purpose', 'file']);
    expect($body->body())->toContain('filename="notes.txt"');
    expect($captured->requests[0]->headers->get('content-type'))->toStartWith('multipart/form-data; boundary=');
});

it('pages token lists until the token is empty', function () {
    [$client, $captured] = mockClient(function ($request, int $n) {
        if ($n === 1) {
            return jsonResponse([
                'data' => [['id' => 'file_1']],
                'pagination_token' => 'next',
            ]);
        }

        return jsonResponse([
            'data' => [['id' => 'file_2']],
            'pagination_token' => null,
        ]);
    });
    $page = $client->files->list();
    $ids = [];
    foreach ($page as $file) {
        $ids[] = $file['id'];
    }

    expect($ids)->toBe(['file_1', 'file_2']);
    expect($captured->requests[1]->url)->toContain('pagination_token=next');
    expect($captured->requests[0]->url)->toContain('sort_by=created_at');
});

it('treats an empty 202 video poll as pending', function () {
    [$client] = mockClient(fn () => jsonResponse(null, 202));
    $video = $client->videos->get('req_1');

    expect($video->status)->toBe('pending');
    expect($video->usage)->toBeNull();
});

it('applies response defaults inside batch requests', function () {
    [$client, $captured] = mockClient(fn () => jsonResponse(null, 200));
    $client->batches->requests->add('batch_1', [
        'batch_requests' => [[
            'batch_request_id' => '1',
            'batch_request' => [
                'responses' => [
                    'model' => 'grok-4.6',
                    'input' => 'hi',
                ],
            ],
        ]],
    ]);

    $sent = $captured->requests[0]->json();
    expect($sent['batch_requests'][0]['batch_request']['responses']['store'])->toBeFalse();
    expect($sent['batch_requests'][0]['batch_request']['responses']['include'])->toBe(['reasoning.encrypted_content']);
});

it('returns audio bytes unless timestamps were requested', function () {
    [$client, $captured] = mockClient(function ($request) {
        if ($request->headers->get('accept') === 'application/json') {
            return jsonResponse(['audio' => 'AAAA', 'content_type' => 'audio/mpeg', 'duration' => 1]);
        }

        $headers = HeaderBag::from([
            'content-type' => 'audio/mpeg',
            'x-request-id' => 'req_test',
        ]);

        return new TiborSrc\XaiSdkPhp\Http\HttpResponse(200, $headers, '', new TiborSrc\XaiSdkPhp\Http\StringSource('mp3'));
    });
    $audio = $client->voice->speak(['text' => 'Hi [pause]', 'language' => 'en']);
    $timed = $client->voice->speak(['text' => 'Hi', 'language' => 'en', 'with_timestamps' => true]);

    expect($audio->bytes())->toBe('mp3');
    expect($timed->audio)->toBe('AAAA');
    expect($captured->requests[0]->headers->get('accept'))->toBe('*/*');
    expect($captured->requests[1]->headers->get('accept'))->toBe('application/json');
});

it('inlines image blobs as data urls', function () {
    [$client, $captured] = mockClient(fn () => jsonResponse(completedResponse()));
    $png = "\x89PNG\r\n\x1a\n";
    $client->responses->create([
        'model' => 'grok-4.6',
        'input' => [[
            'type' => 'input_image',
            'image' => new Blob($png, 'application/octet-stream'),
        ]],
        'stream' => false,
    ]);

    $url = $captured->requests[0]->json()['input'][0]['image_url'];
    expect($url)->toStartWith('data:image/png;base64,');
});

it('builds tool objects with the helper type', function () {
    expect(webSearch(['type' => 'other', 'query' => 'x']))->toBe([
        'type' => 'web_search',
        'query' => 'x',
    ]);
    expect(codeExecution())->toBe(['type' => 'code_interpreter']);
});

it('encodes path segments', function () {
    [$client, $captured] = mockClient(fn () => jsonResponse(['id' => 'file/../1']));
    $client->files->get('file/../1');

    expect($captured->requests[0]->url)->toContain('/files/file%2F..%2F1');
});

it('stops a video wait when the deadline passes', function () {
    [$client] = mockClient(fn () => jsonResponse(null, 202));

    expect(fn () => $client->videos->wait('req_1', ['interval' => 0, 'timeout' => 0]))
        ->toThrow(TimeoutError::class, 'Video request req_1 did not finish within 0ms');
});
