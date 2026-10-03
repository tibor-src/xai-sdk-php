<?php

declare(strict_types=1);

use XaiOfficial\Sdk\SpaceXAI;

it('requires an api key', function () {
    putenv('XAI_API_KEY');
    new SpaceXAI();
})->throws(InvalidArgumentException::class);

it('reads api key from environment', function () {
    putenv('XAI_API_KEY=test-env-key');
    $client = new SpaceXAI();
    expect($client->getApiKey())->toBe('test-env-key');
});

it('exposes all resource namespaces', function () {
    $client = new SpaceXAI(['apiKey' => 'k']);
    expect($client->responses)->not->toBeNull();
    expect($client->models)->not->toBeNull();
    expect($client->images)->not->toBeNull();
    expect($client->videos)->not->toBeNull();
    expect($client->files)->not->toBeNull();
    expect($client->batches)->not->toBeNull();
    expect($client->voice)->not->toBeNull();
    expect($client->tokenizer)->not->toBeNull();
    expect($client->account)->not->toBeNull();
});
