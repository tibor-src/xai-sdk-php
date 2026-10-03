<?php

declare(strict_types=1);

use XaiOfficial\Sdk\ModelResponse;
use XaiOfficial\Sdk\Support\Porcelain;
use XaiOfficial\Sdk\Tests\Helpers;
use XaiOfficial\Sdk\Types\HttpMeta;

it('extracts text from output', function () {
    $output = Helpers::COMPLETED_RESPONSE['output'];
    expect(Porcelain::toText($output))->toBe('Hello world');
});

it('converts output to input', function () {
    $output = Helpers::COMPLETED_RESPONSE['output'];
    expect(Porcelain::toInput($output))->toBe($output);
});

it('parses json from completed response', function () {
    $response = new ModelResponse([
        'id' => 'r1',
        'status' => 'completed',
        'output' => [[
            'type' => 'message',
            'content' => [['type' => 'output_text', 'text' => '{"ok":true}']],
        ]],
    ], new HttpMeta(200, [], 'req', 'client'));
    expect($response->toJson())->toBe(['ok' => true]);
});

it('applies store=false default with encrypted reasoning include', function () {
    $payload = Porcelain::applyCreateDefaults(['model' => 'grok-4.6', 'input' => 'hi']);
    expect($payload['store'])->toBeFalse();
    expect($payload['include'])->toContain('reasoning.encrypted_content');
});

it('identifies output item types', function () {
    expect(Porcelain::isMessage(['type' => 'message']))->toBeTrue();
    expect(Porcelain::isReasoning(['type' => 'reasoning']))->toBeTrue();
    expect(Porcelain::isFunctionCall(['type' => 'function_call']))->toBeTrue();
    expect(Porcelain::isImageGenerationCall(['type' => 'image_generation_call']))->toBeTrue();
});
