<?php

declare(strict_types=1);

use TiborSrc\XaiSdkPhp\Env;
use TiborSrc\XaiSdkPhp\SpaceXAI;

it('creates a response against the live API', function () {
    $client = new SpaceXAI([
        'timeout' => 120_000,
    ]);

    $response = $client->responses->create([
        'model' => 'grok-4.6',
        'input' => 'Reply with the single word OK.',
    ]);

    expect($response->id)->not->toBe('')
        ->and($response->status)->toBe('completed')
        ->and($response->toText())->not->toBe('')
        ->and($response->http->status)->toBe(200)
        ->and($response->usage->total_tokens)->toBeGreaterThan(0);
})->skip(
    fn (): bool => Env::apiKey() === null,
    'Set XAI_API_KEY to run this live test',
);
