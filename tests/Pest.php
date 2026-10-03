<?php

declare(strict_types=1);

use TiborSrc\XaiSdkPhp\Http\HeaderBag;
use TiborSrc\XaiSdkPhp\Http\HttpRequest;
use TiborSrc\XaiSdkPhp\Http\HttpResponse;
use TiborSrc\XaiSdkPhp\Http\StringSource;
use TiborSrc\XaiSdkPhp\SpaceXAI;

function completedResponse(array $overrides = []): array
{
    $response = [
        'id' => 'resp_123',
        'object' => 'response',
        'created_at' => 1754475266,
        'model' => 'grok-4.6',
        'status' => 'completed',
        'store' => false,
        'incomplete_details' => null,
        'output' => [
            [
                'type' => 'reasoning',
                'id' => 'rs_1',
                'summary' => [['type' => 'summary_text', 'text' => 'think']],
                'encrypted_content' => 'enc_abc',
                'status' => 'completed',
            ],
            [
                'type' => 'message',
                'id' => 'msg_1',
                'role' => 'assistant',
                'status' => 'completed',
                'content' => [['type' => 'output_text', 'text' => 'Hello world', 'annotations' => []]],
            ],
        ],
        'usage' => [
            'input_tokens' => 32,
            'output_tokens' => 9,
            'total_tokens' => 151,
            'input_tokens_details' => ['cached_tokens' => 8, 'extra' => 1],
            'output_tokens_details' => ['reasoning_tokens' => 110],
            'num_sources_used' => 0,
            'num_server_side_tools_used' => 0,
            'cost_in_nano_usd' => 1_500_000_000,
            'cost_in_usd_ticks' => 15_000_000_000,
            'context_details' => ['cached' => true],
        ],
    ];

    foreach ($overrides as $key => $value) {
        $response[$key] = $value;
    }

    return $response;
}

function jsonResponse(mixed $body, int $status = 200, array $headers = []): HttpResponse
{
    $bag = HeaderBag::from(array_merge([
        'content-type' => 'application/json',
        'x-request-id' => 'req_test',
    ], $headers));
    $encoded = $body === null ? '' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    return new HttpResponse($status, $bag, '', $encoded === '' ? null : new StringSource($encoded));
}

function sseResponse(array $events, array $headers = []): HttpResponse
{
    $raw = '';
    foreach ($events as $event) {
        $raw .= 'data: ' . json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
    }
    $raw .= "data: [DONE]\n\n";
    $bag = HeaderBag::from(array_merge([
        'content-type' => 'text/event-stream',
        'x-request-id' => 'req_test',
    ], $headers));

    return new HttpResponse(200, $bag, '', new StringSource($raw));
}

final class Capture
{
    /** @var list<HttpRequest> */
    public array $requests = [];
}

function mockClient(callable $handler, array $opts = []): array
{
    $capture = new Capture();
    $fetch = function (HttpRequest $request) use ($capture, $handler): HttpResponse {
        $capture->requests[] = $request;

        return $handler($request, count($capture->requests));
    };
    $client = new SpaceXAI(array_merge([
        'apiKey' => 'k',
        'maxRetries' => 0,
        'fetch' => $fetch,
    ], $opts));

    return [$client, $capture];
}
