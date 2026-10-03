<?php

declare(strict_types=1);

use XaiOfficial\Sdk\Support\UsageMapper;
use XaiOfficial\Sdk\Tests\Helpers;

it('maps usage with cost_usd from ticks', function () {
    $usage = UsageMapper::mapUsage(Helpers::USAGE_FIXTURE);
    expect($usage['cost_usd'])->toBe(1.5);
    expect($usage['input_tokens'])->toBe(32);
});

it('maps media usage', function () {
    $usage = UsageMapper::mapMediaUsage(['cost_in_usd_ticks' => 10_000_000_000]);
    expect($usage['cost_usd'])->toBe(1.0);
});
