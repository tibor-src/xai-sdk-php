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
        ['Hi [laff] there.', ['Unknown speech tag [laff], did you mean [laugh]?']],
        [
            'Hi [laff] there. <wisper>Quiet.</wisper>',
            ['Unknown speech tag [laff], did you mean [laugh]?', 'Unknown speech tag <wisper>, did you mean <whisper>?'],
        ],
        ['Then a [door-creak] sounded.', ['Unknown speech tag [door-creak].']],
        [
            'Ha [laughs], [sighing], [long-paws].',
            [
                'Unknown speech tag [laughs], did you mean [laugh]?',
                'Unknown speech tag [sighing], did you mean [sigh]?',
                'Unknown speech tag [long-paws], did you mean [long-pause]?',
            ],
        ],
        ['<lower>Listen.</lower>', ['Unknown speech tag <lower>, did you mean <lower-pitch>?']],
        ['Wait [whisper] now.', ['[whisper] is a wrapping tag, use <whisper>…</whisper>.']],
        ['Wait <pause> now.', ['<pause> is an inline tag, use [pause].']],
        ['<whisper>It is a secret.', ['<whisper> is never closed.']],
        ['It is a secret.</whisper>', ['</whisper> has no opening tag.']],
        ['It is a secret.</wisper>', ['Unknown speech tag </wisper>, did you mean </whisper>?']],
        ['<slow><soft>Goodnight.</slow></soft>', ['Close <soft> before </slow>.']],
        [
            'Grok said so.<citation id="web:23"/> <b>Bold</b> </grok:render>',
            ['<citation id="web:23"/> is not a speech tag.', 'Unknown speech tag <b>.', '</grok:render> is not a speech tag.'],
        ],
        [
            '<whisper volume="low">Quiet.</whisper> <pause/>',
            ['<whisper volume="low"> is not a speech tag.', '</whisper> has no opening tag.', '<pause/> is not a speech tag.'],
        ],
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
        ['They said [they] would come [laughs].', 'They said [they] would come .'],
        [
            'Grok said so.<citation id="web:23"/> <whisper>Quiet <b>now</b>.</whisper>',
            'Grok said so. <whisper>Quiet now.</whisper>',
        ],
        ['<whisper volume="low">Quiet.</whisper> <pause/> <grok:render type="x">23</grok:render>', 'Quiet.  23'],
        ['<a title="[laff]">Link</a>', 'Link'],
    ];

    foreach ($cases as [$text, $stripped]) {
        expect(stripInvalidSpeechTags($text))->toBe($stripped);
        expect(checkSpeechText(stripInvalidSpeechTags($text)))->toBe([]);
    }
});

it('reads bracketed words that do not resemble a known tag as text', function () {
    foreach ([
        'They said [they] would bring [them] to [the] show.',
        'He said it was fine [sic].',
        'Then the [music] started.',
    ] as $text) {
        expect(checkSpeechText($text))->toBe([]);
        expect(stripInvalidSpeechTags($text))->toBe($text);
    }
});

it('leaves text that only looks like markup', function () {
    $text = 'Visit <https://x.ai> or write to <support@x.ai>. If a < b and c > d, then <3.';

    expect(checkSpeechText($text))->toBe([]);
    expect(stripInvalidSpeechTags($text))->toBe($text);
});
