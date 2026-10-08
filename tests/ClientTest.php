<?php

declare(strict_types=1);

use TiborSrc\XaiSdkPhp\APIConnectionError;
use TiborSrc\XaiSdkPhp\APIProtocolError;
use TiborSrc\XaiSdkPhp\AuthenticationError;
use TiborSrc\XaiSdkPhp\Http\Blob;
use TiborSrc\XaiSdkPhp\Http\ChunkSource;
use TiborSrc\XaiSdkPhp\Http\File;
use TiborSrc\XaiSdkPhp\Http\FormData;
use TiborSrc\XaiSdkPhp\Http\HeaderBag;
use TiborSrc\XaiSdkPhp\IdleStream;
use TiborSrc\XaiSdkPhp\ModelResponse;
use TiborSrc\XaiSdkPhp\RateLimitError;
use TiborSrc\XaiSdkPhp\SpaceXAI;
use TiborSrc\XaiSdkPhp\Sse;
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
    expect($captured->requests[0]->headers->get('user-agent'))->toBe('xai-sdk/0.2.3 (php)');
    expect($captured->requests[0]->headers->get('xai-sdk-version'))->toBe('php/0.2.3');
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
    expect($captured->requests[0]->headers->get('xai-sdk-version'))->toBe('php/0.2.3');
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

it('sends service_tier fast and reads back the tier that served the request', function () {
    [$client, $captured] = mockClient(fn () => jsonResponse(completedResponse(['service_tier' => 'fast'])));
    $response = $client->responses->create([
        'model' => 'grok-4.7',
        'input' => 'hi',
        'service_tier' => 'fast',
        'stream' => false,
    ]);

    expect($captured->requests[0]->json()['service_tier'])->toBe('fast');
    expect($response->service_tier)->toBe('fast');
});

it('accepts a json response when retryBeforeOutput retries create without stream', function () {
    [$client, $captured] = mockClient(function ($request, int $n) {
        if ($n === 1) {
            return sseResponse([
                ['type' => 'response.created', 'response' => ['id' => 'resp_1', 'status' => 'in_progress', 'output' => []]],
            ], ['x-request-id' => 'req_1']);
        }

        return jsonResponse(completedResponse(), 200, ['x-request-id' => 'req_2']);
    }, ['maxRetries' => 2, 'retryBeforeOutput' => true]);

    $response = $client->responses->create([
        'model' => 'grok-4.6',
        'input' => 'hi',
    ]);

    expect($response)->toBeInstanceOf(ModelResponse::class);
    expect($response->toText())->toBe('Hello world');
    expect($response->http->requestId)->toBe('req_2');
    expect($captured->requests)->toHaveCount(2);
    expect($captured->requests[1]->headers->get('accept'))->toBe('text/event-stream');
});

it('sends image upload urls and returns them as the image urls', function () {
    $uploadUrl = 'https://storage.example.com/images/cat.jpg?X-Signature=abc123';
    [$client, $captured] = mockClient(fn () => jsonResponse([
        'data' => [['url' => $uploadUrl, 'mime_type' => 'image/jpeg']],
        'usage' => ['cost_in_usd_ticks' => 200_000_000],
    ]));
    $params = [
        'model' => 'grok-imagine-image-2.0',
        'prompt' => 'A cat in a tree',
        'response_format' => 'url',
        'output' => ['upload_urls' => [$uploadUrl]],
    ];
    $result = $client->images->generate($params);

    expect($captured->requests[0]->json())->toBe($params);
    expect($result->data)->toBe([['url' => $uploadUrl, 'mime_type' => 'image/jpeg']]);
    expect($result->usage->cost_usd)->toBe(0.02);
});

it('sends image edit upload urls alongside an inlined source image', function () {
    $uploadUrl = 'https://storage.example.com/images/cat.jpg?X-Signature=abc123';
    [$client, $captured] = mockClient(fn () => jsonResponse([
        'data' => [['url' => $uploadUrl, 'mime_type' => 'image/jpeg']],
    ]));
    $result = $client->images->edit([
        'model' => 'grok-imagine-image-2.0',
        'prompt' => 'Add a hat',
        'image' => new Blob('png', 'image/png'),
        'output' => ['upload_urls' => [$uploadUrl]],
    ]);

    expect($captured->requests[0]->json())->toBe([
        'model' => 'grok-imagine-image-2.0',
        'prompt' => 'Add a hat',
        'output' => ['upload_urls' => [$uploadUrl]],
        'image' => ['url' => 'data:image/png;base64,' . base64_encode('png')],
    ]);
    expect($result->data[0]['url'])->toBe($uploadUrl);
});

it('inlines blobs anywhere in a video generation request', function () {
    $png = "\x89PNG\r\n\x1a\n";
    $wav = 'RIFF' . "\x24\x00\x00\x00" . 'WAVEfmt ';
    $id3 = 'ID3' . "\x04\x00\x00\x00\x00\x00\x00";
    $mpegFrame = "\xff\xfb\x90\x00";
    $lastFrame = new Blob($png);
    $narrator = new Blob('mp3-bytes', 'audio/mpeg');
    [$client, $captured] = mockClient(fn () => jsonResponse(['request_id' => 'req_vid']));
    $params = [
        'model' => 'grok-imagine-video-1.5',
        'prompt' => 'A lighthouse at dusk',
        'image' => ['url' => 'https://example.com/first.png'],
        'last_frame' => $lastFrame,
        'reference_audios' => [
            $narrator,
            new Blob($wav),
            new Blob($id3),
            new Blob($mpegFrame),
            new Blob('?'),
            ['voice_id' => 'ara'],
            ['url' => 'https://example.com/voice.wav'],
        ],
        'output' => ['upload_url' => 'https://example.com/upload'],
    ];
    $client->videos->generate($params);

    expect($captured->requests[0]->json())->toBe([
        'model' => 'grok-imagine-video-1.5',
        'prompt' => 'A lighthouse at dusk',
        'image' => ['url' => 'https://example.com/first.png'],
        'last_frame' => ['url' => 'data:image/png;base64,' . base64_encode($png)],
        'reference_audios' => [
            ['url' => 'data:audio/mpeg;base64,' . base64_encode('mp3-bytes')],
            ['url' => 'data:audio/wav;base64,' . base64_encode($wav)],
            ['url' => 'data:audio/mpeg;base64,' . base64_encode($id3)],
            ['url' => 'data:audio/mpeg;base64,' . base64_encode($mpegFrame)],
            ['url' => 'data:application/octet-stream;base64,' . base64_encode('?')],
            ['voice_id' => 'ara'],
            ['url' => 'https://example.com/voice.wav'],
        ],
        'output' => ['upload_url' => 'https://example.com/upload'],
    ]);
    expect($params['last_frame'])->toBe($lastFrame);
    expect($params['reference_audios'][0])->toBe($narrator);
});

it('keeps polling a batch that has no requests yet', function () {
    [$client, $captured] = mockClient(function ($request, int $n) {
        if ($n === 1) {
            return jsonResponse(batchBody(0, 0));
        }

        return jsonResponse(batchBody(0, 2));
    });
    $batch = $client->batches->wait('batch_1', ['interval' => 0, 'timeout' => 5_000]);

    expect($captured->requests)->toHaveCount(2);
    expect($batch->state['num_requests'])->toBe(2);
});

it('returns a batch without requests once it is cancelled or expires', function () {
    [$client, $captured] = mockClient(function ($request, int $n) {
        if ($n === 1) {
            return jsonResponse(batchBody(0, 0));
        }

        return jsonResponse(batchBody(0, 0, ['cancel_time' => '2025-11-11T12:00:04Z']));
    });
    $cancelled = $client->batches->wait('batch_1', ['interval' => 0, 'timeout' => 5_000]);

    expect($captured->requests)->toHaveCount(2);
    expect($cancelled->cancel_time)->toBe('2025-11-11T12:00:04Z');

    [$xai, $xaiCaptured] = mockClient(fn () => jsonResponse(batchBody(0, 0, [
        'cancel_by_xai_message' => 'Batch cancelled by SpaceXAI',
    ])));
    $byXai = $xai->batches->wait('batch_1', ['interval' => 0, 'timeout' => 5_000]);
    expect($xaiCaptured->requests)->toHaveCount(1);
    expect($byXai->cancel_by_xai_message)->toBe('Batch cancelled by SpaceXAI');

    [$expiredClient, $expiredCaptured] = mockClient(fn () => jsonResponse(batchBody(0, 0, [
        'expire_time' => '2000-01-01',
    ])));
    $expired = $expiredClient->batches->wait('batch_1', ['interval' => 0, 'timeout' => 5_000]);
    expect($expiredCaptured->requests)->toHaveCount(1);
    expect($expired->expire_time)->toBe('2000-01-01');
});

it('times out a batch that never gets requests and does not expire', function () {
    [$client] = mockClient(fn () => jsonResponse(batchBody(0, 0, ['expire_time' => null])));

    expect(fn () => $client->batches->wait('batch_1', ['interval' => 0, 'timeout' => 0]))
        ->toThrow(TimeoutError::class, 'Batch batch_1 did not finish within 0ms');
});

it('names an unnamed audio blob after its format', function () {
    $bytes = "\xff\xfb\x90\x00";
    $wav = 'RIFF' . "\x24\x00\x00\x00" . 'WAVEfmt ';
    [$client, $captured] = mockClient(fn () => jsonResponse(['text' => 'hello', 'voice_id' => 'voice_1']));
    $files = [
        new Blob($bytes, 'audio/mpeg'),
        new Blob($bytes, 'audio/x-wav'),
        new Blob($bytes, 'audio/ogg; codecs=opus'),
        new Blob($bytes, 'video/x-matroska'),
        new File($bytes, '', 'audio/flac'),
        new File($bytes, 'call.mp3', 'audio/wav'),
        new Blob($bytes, 'audio/webm'),
        new Blob($bytes, 'video/webm'),
        new Blob($bytes, 'audio/aiff'),
    ];
    foreach ($files as $file) {
        $client->voice->transcribe(['file' => $file, 'diarize' => true]);
    }
    $client->voice->transcribe([
        'file' => new Blob($bytes, 'audio/mpeg'),
        'audio_format' => 'mp3',
    ]);
    $client->voice->custom->create([
        'file' => new Blob($wav),
        'name' => 'Friendly Narrator',
    ]);

    $names = array_map(
        static fn ($request): string => formFilename($request->body),
        $captured->requests,
    );
    expect($names)->toBe([
        'audio.mp3',
        'audio.wav',
        'audio.ogg',
        'audio.mkv',
        'audio.flac',
        'call.mp3',
        'audio.webm',
        'audio.webm',
        'blob',
        'blob',
        'audio.wav',
    ]);
});

it('names an unnamed blob without a mime type after the format its first bytes show', function () {
    $wav = 'RIFF' . "\x24\x00\x00\x00" . 'WAVEfmt ';
    $oggPage = 'OggS' . "\x00\x02" . str_repeat("\x00", 20);
    [$client, $captured] = mockClient(fn () => jsonResponse(['text' => 'hello']));
    $files = [
        new Blob('ID3' . "\x04\x00\x00\x00\x00\x00\x00"),
        new Blob("\xff\xfb\x90\x00"),
        new Blob("\xff\xf1\x50\x80\x02\x1f\xfc"),
        new Blob($wav . str_repeat("\x00", 100)),
        new Blob('fLaC' . "\x00\x00\x00\x22"),
        new Blob($oggPage . "\x01\x1e\x01" . 'vorbis'),
        new Blob($oggPage . "\x01\x13" . 'OpusHead' . "\x01\x02"),
        new Blob('OggS'),
        new Blob("\x00\x00\x00\x20" . 'ftypM4A ' . "\x00\x00\x00\x00"),
        new Blob("\x00\x00\x00\x20" . 'ftypM4B ' . "\x00\x00\x00\x00"),
        new Blob("\x00\x00\x00\x18" . 'ftypisom' . "\x00\x00\x02\x00"),
        new Blob("\x1a\x45\xdf\xa3" . 'matroska'),
        new Blob("\x1a\x45\xdf\xa3" . 'webm'),
        new File($wav, '', 'application/octet-stream'),
        new Blob("\x1a\x45\xdf\xa3\x9f"),
        new Blob("\xff\xfd\x90\x00"),
        new Blob('Hello'),
        new Blob("\xff"),
        new Blob(''),
        new File($wav, 'call.bin'),
        new Blob($wav, 'audio/webm'),
    ];
    foreach ($files as $file) {
        $client->voice->transcribe(['file' => $file, 'diarize' => true]);
    }
    $client->voice->transcribe(['file' => new Blob($wav), 'audio_format' => 'wav']);

    $names = array_map(
        static fn ($request): string => formFilename($request->body),
        $captured->requests,
    );
    expect($names)->toBe([
        'audio.mp3',
        'audio.mp3',
        'audio.aac',
        'audio.wav',
        'audio.flac',
        'audio.ogg',
        'audio.opus',
        'audio.ogg',
        'audio.m4a',
        'audio.m4a',
        'audio.mp4',
        'audio.mkv',
        'audio.webm',
        'audio.wav',
        'blob',
        'blob',
        'blob',
        'blob',
        'blob',
        'call.bin',
        'audio.webm',
        'blob',
    ]);
});

it('parses a stream event larger than 1 MiB', function () {
    $event = [
        'type' => 'response.output_item.done',
        'item' => ['type' => 'reasoning', 'encrypted_content' => str_repeat('e', 1_500_000)],
    ];
    $raw = 'data: ' . json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
    $chunks = [];
    for ($offset = 0, $length = strlen($raw); $offset < $length; $offset += 16_384) {
        $chunks[] = [substr($raw, $offset, 16_384), 0];
    }
    $body = new IdleStream(new ChunkSource($chunks), 0, null, null);
    $events = [];
    foreach (Sse::parse($body) as $parsed) {
        $events[] = $parsed;
    }

    expect($events)->toBe([$event]);
});

it('finds a blank line split across chunks and honors maxEventChars', function () {
    $raw = "data: {\"type\":\"ping\"}\r\n\r\ndata: {\"type\":\"response.completed\"}\n\n";
    $pieces = ["data: {\"type\":\"ping\"}\r", "\n\r", "\ndata: {\"type\":\"response.completed\"}\n", "\n"];
    $chunks = array_map(static fn (string $piece): array => [$piece, 0], $pieces);
    $split = new IdleStream(new ChunkSource($chunks), 0, null, null);
    $events = [];
    foreach (Sse::parse($split) as $parsed) {
        $events[] = $parsed;
    }
    expect($events)->toBe([['type' => 'ping'], ['type' => 'response.completed']]);

    $over = new IdleStream(new ChunkSource([['data: ' . str_repeat('x', 32), 0]], ), 0, null, null);
    expect(fn () => iterator_to_array(Sse::parse($over, null, 32)))
        ->toThrow(RuntimeException::class, 'SSE event exceeds 32 characters');

    $open = new IdleStream(new ChunkSource([
        ['data: ', 0],
        [str_repeat('x', 32), 0],
    ]), 0, null, null);
    expect(fn () => iterator_to_array(Sse::parse($open, null, 32)))
        ->toThrow(RuntimeException::class, 'SSE event exceeds 32 characters');

    $any = ['type' => 'ping', 'padding' => str_repeat('x', 64)];
    $unlimited = new IdleStream(new ChunkSource([
        ['data: ' . json_encode($any) . "\n\n", 0],
    ]), 0, null, null);
    expect(iterator_to_array(Sse::parse($unlimited, null, 0)))->toBe([$any]);
});

it('reads multi-agent encrypted reasoning larger than 1 MiB and limits each event', function () {
    $reasoning = [
        'type' => 'reasoning',
        'id' => 'rs_agents',
        'summary' => [],
        'encrypted_content' => str_repeat('e', 2 * 1024 * 1024),
        'status' => 'completed',
    ];
    $message = completedResponse()['output'][1];
    $done = completedResponse([
        'model' => 'grok-4.20-multi-agent',
        'output' => [$reasoning, $message],
    ]);
    $events = [
        ['type' => 'response.output_item.done', 'output_index' => 0, 'item' => $reasoning],
        ['type' => 'response.completed', 'response' => $done],
    ];
    [$client, $captured] = mockClient(fn () => sseResponse($events));
    $streamed = $client->responses->create([
        'model' => 'grok-4.20-multi-agent',
        'input' => 'Research this',
        'stream' => true,
    ])->done();
    $created = $client->responses->create([
        'model' => 'grok-4.20-multi-agent',
        'input' => 'Research this',
    ]);

    expect($streamed->toInput()[0])->toBe($reasoning);
    expect($created->toInput()[0])->toBe($reasoning);
    expect($streamed->toText())->toBe('Hello world');
    expect($created->toText())->toBe('Hello world');
    expect($captured->requests[0]->json()['include'])->toBe(['reasoning.encrypted_content']);
    expect($captured->requests[1]->json()['include'])->toBe(['reasoning.encrypted_content']);

    [$limited] = mockClient(fn () => sseResponse($events), ['maxResponseBodyBytes' => 1_048_576]);
    expect(fn () => $limited->responses->create([
        'model' => 'grok-4.20-multi-agent',
        'input' => 'Research this',
    ]))->toThrow(APIConnectionError::class, 'SSE event exceeds 1048576 characters');
    $stream = $limited->responses->create([
        'model' => 'grok-4.20-multi-agent',
        'input' => 'Research this',
        'stream' => true,
    ]);
    expect(fn () => $stream->done())->toThrow(APIConnectionError::class, 'SSE event exceeds 1048576 characters');

    $raised = $limited->responses->create([
        'model' => 'grok-4.20-multi-agent',
        'input' => 'Research this',
    ], ['maxResponseBodyBytes' => 4 * 1_048_576]);
    expect($raised->output)->toHaveCount(2);
});

it('lists the model ids added in 0.2.3', function () {
    expect(\TiborSrc\XaiSdkPhp\KNOWN_MODEL_IDS)->toContain('grok-4.20-multi-agent');
    expect(\TiborSrc\XaiSdkPhp\KNOWN_VIDEO_MODEL_IDS)->toContain('grok-imagine-video-1.5-lite');
});

/** @param array<string, mixed> $extra */
function batchBody(int $pending, int $requests, array $extra = []): array
{
    return array_merge([
        'batch_id' => 'batch_1',
        'expire_time' => '2099-01-01',
        'state' => ['num_pending' => $pending, 'num_requests' => $requests],
    ], $extra);
}

function formFilename(mixed $body): string
{
    expect($body)->toBeInstanceOf(FormData::class);
    expect($body->body())->toMatch('/filename="([^"]*)"/');
    preg_match('/filename="([^"]*)"/', $body->body(), $match);

    return $match[1];
}
