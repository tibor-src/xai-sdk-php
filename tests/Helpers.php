<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use XaiOfficial\Sdk\SpaceXAI;

final class Helpers
{
    public const USAGE_FIXTURE = [
        'input_tokens' => 32,
        'output_tokens' => 9,
        'total_tokens' => 151,
        'input_tokens_details' => ['cached_tokens' => 8],
        'output_tokens_details' => ['reasoning_tokens' => 110],
        'num_sources_used' => 0,
        'num_server_side_tools_used' => 0,
        'cost_in_nano_usd' => 1_500_000_000,
        'cost_in_usd_ticks' => 15_000_000_000,
    ];

    public const COMPLETED_RESPONSE = [
        'id' => 'resp_123',
        'object' => 'response',
        'created_at' => 1_754_475_266,
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
        'usage' => self::USAGE_FIXTURE,
    ];

    public static function jsonResponse(mixed $body, int $status = 200, array $headers = []): Response
    {
        return new Response($status, array_merge([
            'content-type' => 'application/json',
            'x-request-id' => 'req_test',
        ], $headers), json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<int, mixed> $events
     */
    public static function sseResponse(array $events, int $status = 200, bool $hang = false): Response
    {
        $body = '';
        foreach ($events as $event) {
            $body .= 'data: '.json_encode($event, JSON_THROW_ON_ERROR)."\n\n";
        }
        if (! $hang) {
            $body .= "data: [DONE]\n\n";
        }

        return new Response($status, [
            'content-type' => 'text/event-stream',
            'x-request-id' => 'req_test',
        ], $body);
    }

    /**
     * @param array<int, Response> $responses
     */
    public static function clientWithMock(array $responses, ?array &$captured = null, int $maxRetries = 0): SpaceXAI
    {
        $n = 0;
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        if ($captured !== null) {
            $stack->push(static function (callable $handler) use (&$captured, &$n) {
                return static function ($request, $options) use ($handler, &$captured, &$n) {
                    $n++;
                    $captured[] = [
                        'n' => $n,
                        'method' => $request->getMethod(),
                        'path' => $request->getUri()->getPath(),
                        'headers' => $request->getHeaders(),
                        'body' => (string) $request->getBody(),
                    ];

                    return $handler($request, $options);
                };
            });
        }
        $http = new Client(['handler' => $stack]);

        return new SpaceXAI(['apiKey' => 'test-key', 'httpClient' => $http, 'maxRetries' => $maxRetries]);
    }

    public static function createBody(array $overrides = []): array
    {
        return array_merge([
            'model' => 'grok-4.6',
            'input' => 'Hello',
        ], $overrides);
    }
}
