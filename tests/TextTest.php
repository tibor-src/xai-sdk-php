<?php

declare(strict_types=1);

use function TiborSrc\XaiSdkPhp\checkSpeechText;
use function TiborSrc\XaiSdkPhp\parsePartialJson;
use function TiborSrc\XaiSdkPhp\stripInvalidSpeechTags;

it('closes unfinished json and drops values that may still change', function () {
    $cases = [
        ['', null],
        ['  ', null],
        ['{', []],
        ['{"na', []],
        ['{"name"', []],
        ['{"name":', []],
        ['{"name": "Gro', ['name' => 'Gro']],
        ['{"name": "Grok", "lines": [', ['name' => 'Grok', 'lines' => []]],
        ['{"lines": [{"speaker": "A", "text": "Hi\\', ['lines' => [['speaker' => 'A', 'text' => 'Hi']]]],
        ['{"score": -0.8', []],
        ['{"score": -0.8 ', ['score' => -0.8]],
        ['{"score": -0.8,', ['score' => -0.8]],
        ['{"ok": tr', ['ok' => true]],
        ['{"ok": nul', ['ok' => null]],
        ['[1, 2', [1]],
        ['[1, 2]', [1, 2]],
        ['"\\u00e9t\\u00', 'ét'],
        ['"\\ud83d', ''],
        ['"tab\\tquote\\"', "tab\tquote\""],
    ];

    foreach ($cases as [$text, $value]) {
        expect(parsePartialJson($text))->toEqual($value);
    }
});

it('returns null for text that cannot become json', function () {
    foreach (['{"a": 1} x', '{"a": 01}', '{"a" 1}', '[1,]', '{"a": tx', '"\\x"', '"\\u12zz"', '{a', "\"a\nb\""] as $text) {
        expect(parsePartialJson($text))->toBeNull();
    }
});

it('reports the same speech tag problems as the typescript checker', function () {
    $cases = [
        ['Hi [luff] there.', ['Unknown speech tag [luff], did you mean [laugh]?']],
        [
            'Hi [luff] there. <wisper>Quiet.</wisper>',
            ['Unknown speech tag [luff], did you mean [laugh]?', 'Unknown speech tag <wisper>, did you mean <whisper>?'],
        ],
        ['Then the [music] started.', ['Unknown speech tag [music].']],
        ['<lower>Listen.</lower>', ['Unknown speech tag <lower>, did you mean <lower-pitch>?']],
        ['Wait [whisper] now.', ['[whisper] is a wrapping tag, use <whisper>…</whisper>.']],
        ['Wait <pause> now.', ['<pause> is an inline tag, use [pause].']],
        ['<whisper>It is a secret.', ['<whisper> is never closed.']],
        ['It is a secret.</whisper>', ['</whisper> has no opening tag.']],
        ['It is a secret.</wisper>', ['Unknown speech tag </wisper>, did you mean </whisper>?']],
        ['<slow><soft>Goodnight.</slow></soft>', ['Close <soft> before </slow>.']],
        ['<slow><soft>Goodnight.</soft></slow> [pause] Press [Enter] [1] [citation needed].', []],
    ];

    foreach ($cases as [$text, $problems]) {
        expect(checkSpeechText($text))->toBe($problems);
    }
});

it('strips invalid speech tags and keeps the words they wrap', function () {
    $cases = [
        ['Ha [laff] yes [laugh].', 'Ha  yes [laugh].'],
        ['<wisper>Quiet.</wisper> Done.', 'Quiet. Done.'],
        ['Wait [whisper] <pause>now.', 'Wait  now.'],
        ['<whisper>It is a secret.', 'It is a secret.'],
        ['It is a secret.</whisper>', 'It is a secret.'],
        ['<slow><soft>Goodnight.</slow></soft>', '<soft>Goodnight.</soft>'],
        ['<slow><soft>Goodnight.</soft></slow> [pause] Press [Enter].', '<slow><soft>Goodnight.</soft></slow> [pause] Press [Enter].'],
        ['[[laff]laff] then [[laff]pause]', ' then [pause]'],
    ];

    foreach ($cases as [$text, $stripped]) {
        expect(stripInvalidSpeechTags($text))->toBe($stripped);
        expect(checkSpeechText(stripInvalidSpeechTags($text)))->toBe([]);
    }
});
