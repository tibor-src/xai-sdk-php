<?php

declare(strict_types=1);

use XaiOfficial\Sdk\SpaceXAI;

it('resolves the xai client from the container', function () {
    $app = require __DIR__.'/bootstrap.php';
    $client = $app->make(SpaceXAI::class);
    expect($client)->toBeInstanceOf(SpaceXAI::class);
    expect($client->getApiKey())->toBe('test-key');
});
