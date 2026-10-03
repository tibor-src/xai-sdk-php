<?php

declare(strict_types=1);

use XaiOfficial\Sdk\Support\SpeechTags;

it('accepts valid inline tags', function () {
    $result = SpeechTags::checkSpeechText('Hello [pause] world');
    expect($result['valid'])->toBeTrue();
});

it('rejects unknown tags', function () {
    $result = SpeechTags::checkSpeechText('Hello [not-a-tag] world');
    expect($result['valid'])->toBeFalse();
});

it('strips invalid tags', function () {
    expect(SpeechTags::stripInvalidSpeechTags('Hi [not-a-tag] there'))
        ->toBe('Hi  there');
});
