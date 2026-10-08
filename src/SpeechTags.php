<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp;

final class SpeechTag
{
    public function __construct(
        public readonly string $kind,
        public readonly string $name,
        public readonly int $start,
        public readonly int $end,
    ) {}
}

final class SpeechTags
{
    /** @return list<string> */
    public static function check(string $text): array
    {
        $problems = [];
        foreach (self::inlineTags($text) as $tag) {
            if (! in_array($tag->name, INLINE_SPEECH_TAGS, true)) {
                $problems[] = self::unknownInline($tag->name);
            }
        }
        $open = [];
        foreach (self::wrappingTags($text) as $tag) {
            if ($tag->kind === 'markup') {
                $problems[] = $tag->name . ' is not a speech tag.';
                continue;
            }
            if ($tag->kind === 'open') {
                $open[] = $tag->name;
                if (! in_array($tag->name, WRAPPING_SPEECH_TAGS, true)) {
                    $problems[] = self::unknownWrapping('<' . $tag->name . '>', $tag->name, '<');
                }
                continue;
            }
            $index = array_key_last(array_filter(
                $open,
                static fn (string $name): bool => $name === $tag->name,
            ));
            $found = false;
            $lastIndex = null;
            for ($cursor = count($open) - 1; $cursor >= 0; $cursor--) {
                if ($open[$cursor] === $tag->name) {
                    $found = true;
                    $lastIndex = $cursor;
                    break;
                }
            }
            unset($index);
            if (! $found) {
                $problems[] = in_array($tag->name, WRAPPING_SPEECH_TAGS, true)
                    ? '</' . $tag->name . '> has no opening tag.'
                    : self::unknownWrapping('</' . $tag->name . '>', $tag->name, '</');
            } elseif ($lastIndex !== count($open) - 1) {
                $problems[] = 'Close <' . $open[array_key_last($open)] . '> before </' . $tag->name . '>.';
                array_splice($open, $lastIndex, 1);
            } else {
                array_pop($open);
            }
        }
        foreach ($open as $name) {
            if (in_array($name, WRAPPING_SPEECH_TAGS, true)) {
                $problems[] = '<' . $name . '> is never closed.';
            }
        }

        return $problems;
    }

    public static function stripInvalid(string $text): string
    {
        $current = $text;
        while (true) {
            $next = self::stripOnce($current);
            if ($next === $current) {
                return $next;
            }
            $current = $next;
        }
    }

    private static function stripOnce(string $text): string
    {
        $tags = array_merge(self::inlineTags($text), self::wrappingTags($text));
        usort($tags, static fn (SpeechTag $a, SpeechTag $b): int => $a->start <=> $b->start);
        $drop = new \SplObjectStorage();
        $open = [];
        foreach ($tags as $tag) {
            if ($tag->kind === 'inline') {
                if (! in_array($tag->name, INLINE_SPEECH_TAGS, true)) {
                    $drop->offsetSet($tag);
                }
            } elseif ($tag->kind === 'markup') {
                $drop->offsetSet($tag);
            } elseif ($tag->kind === 'open') {
                $open[] = $tag;
            } else {
                $index = null;
                for ($cursor = count($open) - 1; $cursor >= 0; $cursor--) {
                    if ($open[$cursor]->name === $tag->name) {
                        $index = $cursor;
                        break;
                    }
                }
                if ($index === null) {
                    $drop->offsetSet($tag);
                    continue;
                }
                $opener = $open[$index];
                array_splice($open, $index, 1);
                $closesInnermost = $index === count($open);
                if (! $closesInnermost || ! in_array($tag->name, WRAPPING_SPEECH_TAGS, true)) {
                    $drop->offsetSet($opener);
                    $drop->offsetSet($tag);
                }
            }
        }
        foreach ($open as $opener) {
            $drop->offsetSet($opener);
        }
        $out = '';
        $last = 0;
        foreach ($tags as $tag) {
            // A bracketed tag can sit inside markup, such as in an attribute, and goes with it.
            if (! $drop->offsetExists($tag) || $tag->start < $last) {
                continue;
            }
            $out .= substr($text, $last, $tag->start - $last);
            $last = $tag->end;
        }

        return $out . substr($text, $last);
    }

    /** @return list<SpeechTag> */
    private static function inlineTags(string $text): array
    {
        $tags = [];
        if (preg_match_all('/\[([^\[\]]*)\]/', $text, $matches, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($matches[0] as $index => $full) {
                $name = $matches[1][$index][0];
                if (self::isInlineTag($name)) {
                    $tags[] = new SpeechTag('inline', $name, $full[1], $full[1] + strlen($full[0]));
                }
            }
        }

        return $tags;
    }

    /** @return list<SpeechTag> */
    private static function wrappingTags(string $text): array
    {
        $tags = [];
        if (preg_match_all('/<(\/?)([^<>]*)>/', $text, $matches, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($matches[0] as $index => $full) {
                $name = $matches[2][$index][0];
                $start = $full[1];
                $end = $full[1] + strlen($full[0]);
                if (preg_match('/^[a-z]+(?:-[a-z]+)*$/', $name) === 1) {
                    $kind = $matches[1][$index][0] !== '' ? 'close' : 'open';
                    $tags[] = new SpeechTag($kind, $name, $start, $end);
                } elseif (preg_match('/^<\/?[A-Za-z][\w.:-]*(?:[\t\n\r ][^<>]*|\/)?>$/', $full[0]) === 1) {
                    $tags[] = new SpeechTag('markup', $full[0], $start, $end);
                }
            }
        }

        return $tags;
    }

    /**
     * Bracketed text is a tag when it is a known tag, has a hyphen like `long-pause`, or resembles a known
     * inline tag, like `[laff]` or `[laughs]`. Other bracketed text, such as `[they]` or `[Enter]`, is read aloud.
     */
    private static function isInlineTag(string $name): bool
    {
        if (preg_match('/^[a-z]+(?:-[a-z]+)*$/', $name) !== 1) {
            return false;
        }
        if (in_array($name, INLINE_SPEECH_TAGS, true) || in_array($name, WRAPPING_SPEECH_TAGS, true) || str_contains($name, '-')) {
            return true;
        }
        foreach (INLINE_SPEECH_TAGS as $tag) {
            if (str_starts_with($name, $tag)) {
                return true;
            }
            if (strlen($name) >= 4 && substr($name, 0, 2) === substr($tag, 0, 2) && abs(strlen($name) - strlen($tag)) <= 2) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $tags */
    private static function closest(string $name, array $tags): ?string
    {
        $best = null;
        $bestScore = 0;
        foreach ($tags as $tag) {
            $prefix = 0;
            $limit = min(strlen($name), strlen($tag));
            while ($prefix < $limit && $name[$prefix] === $tag[$prefix]) {
                $prefix++;
            }
            $distance = abs(strlen($name) - strlen($tag));
            if ($prefix < 2 && ! ($prefix === 1 && $distance <= 2)) {
                continue;
            }
            $score = 2 * $prefix - $distance;
            if ($best === null || $score > $bestScore) {
                $best = $tag;
                $bestScore = $score;
            }
        }

        return $best;
    }

    private static function suggest(string $tag, ?string $suggestion): string
    {
        return $suggestion === null
            ? 'Unknown speech tag ' . $tag . '.'
            : 'Unknown speech tag ' . $tag . ', did you mean ' . $suggestion . '?';
    }

    private static function unknownInline(string $name): string
    {
        if (in_array($name, WRAPPING_SPEECH_TAGS, true)) {
            return '[' . $name . '] is a wrapping tag, use <' . $name . '>…</' . $name . '>.';
        }
        $match = self::closest($name, INLINE_SPEECH_TAGS);

        return self::suggest('[' . $name . ']', $match === null ? null : '[' . $match . ']');
    }

    private static function unknownWrapping(string $tag, string $name, string $opener): string
    {
        if (in_array($name, INLINE_SPEECH_TAGS, true)) {
            return $tag . ' is an inline tag, use [' . $name . '].';
        }
        $match = self::closest($name, WRAPPING_SPEECH_TAGS);

        return self::suggest($tag, $match === null ? null : $opener . $match . '>');
    }
}
