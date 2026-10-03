<?php

declare(strict_types=1);

use XaiOfficial\Sdk\Support\PartialJson;

it('parses complete json', function () {
    expect(PartialJson::parse('{"a":1}'))->toBe(['a' => 1]);
});

it('closes unfinished strings', function () {
    expect(PartialJson::parse('{"a":"hel'))->toBe(['a' => 'hel']);
});

it('closes unfinished arrays', function () {
    expect(PartialJson::parse('[1,2,'))->toBe([1, 2]);
});

it('returns null for empty input', function () {
    expect(PartialJson::parse(''))->toBeNull();
});

it('returns null for invalid start', function () {
    expect(PartialJson::parse('not json'))->toBeNull();
});
