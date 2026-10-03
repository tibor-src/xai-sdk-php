<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Support;

use XaiOfficial\Sdk\VoiceConstants;

final class SpeechTags
{
    /**
     * @return array{valid: bool, issues: array<int, string>}
     */
    public static function checkSpeechText(string $text): array
    {
        $issues = [];
        if (preg_match_all('/\[([^\]]+)\]/', $text, $matches)) {
            foreach ($matches[1] as $tag) {
                if (! self::isValidTag($tag)) {
                    $issues[] = "Unknown speech tag: [{$tag}]";
                }
            }
        }

        return ['valid' => $issues === [], 'issues' => $issues];
    }

    public static function stripInvalidSpeechTags(string $text): string
    {
        return preg_replace_callback('/\[([^\]]+)\]/', static function (array $m): string {
            return self::isValidTag($m[1]) ? $m[0] : '';
        }, $text) ?? $text;
    }

    private static function isValidTag(string $tag): bool
    {
        if (in_array($tag, VoiceConstants::INLINE_SPEECH_TAGS, true)) {
            return true;
        }
        foreach (VoiceConstants::WRAPPING_SPEECH_TAGS as $wrapping) {
            if ($tag === $wrapping || str_starts_with($tag, $wrapping.' ')) {
                return true;
            }
        }

        return false;
    }
}
