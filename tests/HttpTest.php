<?php

declare(strict_types=1);

use XaiOfficial\Sdk\Errors\APIConnectionError;
use XaiOfficial\Sdk\Errors\RateLimitError;
use XaiOfficial\Sdk\Http\HttpTransport;
use XaiOfficial\Sdk\Tests\Helpers;

it('retries 429 on POST but not 500', function () {
    expect(HttpTransport::shouldRetryStatus('POST', 429))->toBeTrue();
    expect(HttpTransport::shouldRetryStatus('POST', 408))->toBeFalse();
    expect(HttpTransport::shouldRetryStatus('POST', 500))->toBeFalse();
    expect(HttpTransport::shouldRetryStatus('GET', 408))->toBeTrue();
    expect(HttpTransport::shouldRetryStatus('GET', 500))->toBeTrue();
    expect(HttpTransport::shouldRetryStatus('POST', 503, true))->toBeTrue();
});

it('honors Retry-After integer seconds', function () {
    expect(HttpTransport::retryDelayMs(0, '0'))->toBe(0);
    expect(HttpTransport::retryDelayMs(0, '1.5'))->toBe(1500);
});

it('redacts credentials in XAI_DEBUG curl', function () {
    putenv('XAI_DEBUG=1');
    $headers = [
        'authorization' => 'Bearer secret-key',
        'cookie' => 'sid=abc',
        'accept' => 'application/json',
    ];
    $curl = HttpTransport::formatCurl('POST', 'https://api.x.ai/v1/responses?api_key=leak', $headers);
    expect($curl)->toContain('Bearer [REDACTED]');
    expect($curl)->not->toContain('secret-key');
    expect($curl)->toContain('Request body omitted');
    putenv('XAI_DEBUG');
});

it('retries server errors for idempotent GET requests', function () {
    $captured = [];
    $client = Helpers::clientWithMock([
        Helpers::jsonResponse(['error' => ['message' => 'down']], 500),
        Helpers::jsonResponse(['object' => 'list', 'data' => [['id' => 'grok-4.6']]]),
    ], $captured, 1);
    $result = $client->models->list();
    expect($result['data'])->toBeArray();
    expect(count($captured))->toBe(2);
});

it('maps 429 to RateLimitError', function () {
    $client = Helpers::clientWithMock([
        Helpers::jsonResponse(['error' => ['message' => 'rate limited']], 429),
    ]);
    $client->responses->create(Helpers::createBody());
})->throws(RateLimitError::class);
