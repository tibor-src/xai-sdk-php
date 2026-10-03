<?php

declare(strict_types=1);

use XaiOfficial\Sdk\Tests\Helpers;

it('generates images', function () {
    $client = Helpers::clientWithMock([
        Helpers::jsonResponse(['data' => [['url' => 'https://img.example/a.png']], 'usage' => null]),
    ]);
    $result = $client->images->generate(['model' => 'grok-imagine-image', 'prompt' => 'cat']);
    expect($result['data'][0]['url'])->toBe('https://img.example/a.png');
});
