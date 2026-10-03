<?php

declare(strict_types=1);

use XaiOfficial\Sdk\Tests\Helpers;

it('creates a response via hidden streaming', function () {
    $events = [
        ['type' => 'response.created', 'response' => ['id' => 'resp_123', 'status' => 'in_progress', 'output' => []]],
        ['type' => 'response.completed', 'response' => Helpers::COMPLETED_RESPONSE],
    ];
    $client = Helpers::clientWithMock([Helpers::sseResponse($events)]);
    $response = $client->responses->create(Helpers::createBody());
    expect($response->id)->toBe('resp_123');
    expect($response->toText())->toBe('Hello world');
});

it('creates a streaming response', function () {
    $events = [
        ['type' => 'response.output_text.delta', 'delta' => 'Hi'],
        ['type' => 'response.completed', 'response' => array_merge(Helpers::COMPLETED_RESPONSE, [
            'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => 'Hi']],
            ]],
        ])],
    ];
    $client = Helpers::clientWithMock([Helpers::sseResponse($events)]);
    $stream = $client->responses->create(array_merge(Helpers::createBody(), ['stream' => true]));
    $text = '';
    $stream->on('text', function (string $delta) use (&$text) {
        $text .= $delta;
    });
    $response = $stream->done();
    expect($text)->toBe('Hi');
    expect($response->status)->toBe('completed');
});
