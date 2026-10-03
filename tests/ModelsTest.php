<?php

declare(strict_types=1);

use XaiOfficial\Sdk\Models;
use XaiOfficial\Sdk\Tests\Helpers;

it('lists known model ids', function () {
    expect(Models::KNOWN_MODEL_IDS)->toContain('grok-4.6');
});

it('lists models from api', function () {
    $client = Helpers::clientWithMock([
        Helpers::jsonResponse(['object' => 'list', 'data' => [['id' => 'grok-4.6']]]),
    ]);
    $result = $client->models->list();
    expect($result['data'][0]['id'])->toBe('grok-4.6');
});
